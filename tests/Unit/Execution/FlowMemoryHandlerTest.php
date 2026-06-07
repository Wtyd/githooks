<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use Mockery;
use Tests\Utils\TestCase\UnitTestCase;
use Symfony\Component\Process\Process;
use Tests\Doubles\FakeMemorySampler;
use Wtyd\GitHooks\Configuration\MemoryBudgetConfiguration;
use Wtyd\GitHooks\Configuration\MemoryThreshold;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Execution\FlowMemoryHandler;
use Wtyd\GitHooks\Execution\FlowResult;
use Wtyd\GitHooks\Execution\JobResult;
use Wtyd\GitHooks\Execution\Memory\MemorySampler;
use Wtyd\GitHooks\Jobs\JobAbstract;

/**
 * Direct unit tests for FlowMemoryHandler. The handler talks to a
 * MemorySampler, a MemoryEvaluator and the Symfony Process objects of the
 * running pool — all of which are platform-sensitive. We exercise the
 * handler through a subclass that injects a FakeMemorySampler via the
 * `buildSampler()` seam, so every branch is reachable on any platform.
 */
class FlowMemoryHandlerTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    /** @test */
    public function setup_returns_false_when_no_thresholds_no_budget_no_stats(): void
    {
        $handler = $this->buildHandler($this->options());

        $this->assertFalse($handler->setup([$this->jobWithoutMemory('phpcs')]));
        $this->assertFalse($handler->isActive());
    }

    /** @test */
    public function setup_activates_when_a_job_declares_a_memory_threshold(): void
    {
        $handler = $this->buildHandler($this->options());
        $job = $this->jobWithThreshold('phpunit', MemoryThreshold::fromInt(1024));

        $this->assertTrue($handler->setup([$this->jobWithoutMemory('phpcs'), $job]));
        $this->assertTrue($handler->isActive());
    }

    /** @test */
    public function setup_activates_when_a_job_declares_a_memory_reserve(): void
    {
        $handler = $this->buildHandler($this->options());

        $this->assertTrue($handler->setup([$this->jobWithReserve('phpunit', 512)]));
        $this->assertTrue($handler->isActive());
    }

    /** @test */
    public function setup_activates_when_flow_declares_a_memory_budget(): void
    {
        $options = $this->options(new MemoryBudgetConfiguration(800, 1500));
        $handler = $this->buildHandler($options);

        $this->assertTrue($handler->setup([$this->jobWithoutMemory('phpcs')]));
        $this->assertTrue($handler->isActive());
    }

    /** @test */
    public function setup_activates_when_stats_are_requested(): void
    {
        $options = $this->options(null, true);
        $handler = $this->buildHandler($options);

        $this->assertTrue($handler->setup([$this->jobWithoutMemory('phpcs')]));
        $this->assertTrue($handler->isActive());
    }

    /**
     * @test
     * Cuando `disabled=true` el flow no consulta el budget, pero los
     * thresholds por job y --stats deben seguir activando el handler
     * (la disable solo se aplica al budget global).
     */
    public function setup_ignores_flow_budget_when_disabled_but_still_activates_for_job_thresholds(): void
    {
        $options = $this->options(new MemoryBudgetConfiguration(800, 1500));

        // disabled=true → budget se ignora; sin job thresholds → false
        $disabledNoJobs = $this->buildHandler($options, true);
        $this->assertFalse($disabledNoJobs->setup([$this->jobWithoutMemory('phpcs')]));

        // disabled=true + job threshold → activo
        $disabledWithJob = $this->buildHandler($options, true);
        $this->assertTrue($disabledWithJob->setup([
            $this->jobWithThreshold('phpunit', MemoryThreshold::fromInt(1024)),
        ]));
    }

    /**
     * @test
     * REQ-038: cuando el sampler no está disponible Y se han pedido
     * thresholds (por job o por budget), el handler emite un aviso por
     * stderr explicando la razón.
     */
    public function setup_emits_warning_when_sampler_unavailable_and_thresholds_requested(): void
    {
        $options = $this->options(new MemoryBudgetConfiguration(800, 1500));
        $sampler = new FakeMemorySampler([[]], false, 'platform unsupported (test)');

        $handler = $this->buildHandlerWithSampler($options, $sampler);

        $this->assertTrue($handler->setup([$this->jobWithoutMemory('phpcs')]));

        $warnings = $handler->__capturedWarnings;
        $this->assertCount(1, $warnings);
        // Pin exact message ordering to kill Concat reorder mutants on
        // line 85 (the prefix MUST come before the reason).
        $this->assertSame(
            '⚠ Memory budget disabled: platform unsupported (test)',
            $warnings[0]
        );
    }

    /** @test */
    public function setup_does_not_emit_warning_when_only_stats_are_requested(): void
    {
        $options = $this->options(null, true);
        $sampler = new FakeMemorySampler([[]], false, 'should not surface');

        $handler = $this->buildHandlerWithSampler($options, $sampler);

        $this->assertTrue($handler->setup([$this->jobWithoutMemory('phpcs')]));

        $this->assertSame([], $handler->__capturedWarnings);
    }

    /** @test */
    public function tick_is_a_noop_before_setup(): void
    {
        $sampler = new FakeMemorySampler([['phpcs' => 100]]);
        $handler = $this->buildHandlerWithSampler($this->options(), $sampler);

        $handler->tick(['phpcs' => $this->runningEntry(123)]);

        $this->assertSame([], $sampler->calls, 'sampler should not be invoked before setup');
    }

    /** @test */
    public function tick_samples_running_pids_and_propagates_them_to_the_evaluator(): void
    {
        $sampler = new FakeMemorySampler([['phpunit' => 600, 'phpstan' => 400]]);
        $options = $this->options(new MemoryBudgetConfiguration(800, 1500));
        $handler = $this->buildHandlerWithSampler($options, $sampler);

        $handler->setup([$this->jobWithoutMemory('phpcs')]);
        $handler->tick([
            'phpunit' => $this->runningEntry(101),
            'phpstan' => $this->runningEntry(202),
        ]);

        $this->assertCount(1, $sampler->calls);
        $this->assertSame(['phpunit' => 101, 'phpstan' => 202], $sampler->calls[0]);
    }

    /** @test */
    public function tick_filters_out_null_pids_from_the_running_set(): void
    {
        $sampler = new FakeMemorySampler([['ready' => 50]]);
        $options = $this->options(new MemoryBudgetConfiguration(800, 1500));
        $handler = $this->buildHandlerWithSampler($options, $sampler);

        $handler->setup([$this->jobWithoutMemory('phpcs')]);
        $handler->tick([
            'ready' => $this->runningEntry(101),
            'just_started' => $this->runningEntry(null),
        ]);

        $this->assertCount(1, $sampler->calls);
        $this->assertSame(['ready' => 101], $sampler->calls[0]);
    }

    /**
     * @test
     * Mata el mutante LogicalAnd → LogicalOr en línea 133: cuando el sampler
     * no está disponible, real salta la rama de muestreo; mutado entraría
     * (porque sampler !== null sigue siendo true) e invocaría sample().
     */
    public function tick_does_not_invoke_sampler_when_sampler_is_unavailable(): void
    {
        $sampler = new FakeMemorySampler([['phpunit' => 9999]], false, 'unsupported');
        $options = $this->options(new MemoryBudgetConfiguration(800, 1500));
        $handler = $this->buildHandlerWithSampler($options, $sampler);

        $handler->setup([$this->jobWithoutMemory('phpcs')]);
        $handler->tick(['phpunit' => $this->runningEntry(101)]);

        $this->assertSame([], $sampler->calls);
    }

    /**
     * @test
     * Mata el mutante Minus → Plus en línea 131 sobre `microtime(true) -
     * $flowStartTime`. Con `+` el `peakAtSecond` saldría como una suma
     * absoluta (~3.5e18s); con `-` queda en valor relativo (~0..1s en
     * tests rápidos).
     */
    public function tick_records_peak_at_second_relative_to_flow_start(): void
    {
        $sampler = new FakeMemorySampler([['phpunit' => 1000]]);
        $options = $this->options(new MemoryBudgetConfiguration(800, 1500));
        $handler = $this->buildHandlerWithSampler($options, $sampler);

        $handler->setup([$this->jobWithoutMemory('phpcs')]);
        $handler->tick(['phpunit' => $this->runningEntry(101)]);

        $flowResult = $this->makeFlowResult();
        $handler->attachStats($flowResult);

        $peakAt = $flowResult->getMemoryBudgetState()->getPeakAtSecond();
        $this->assertGreaterThanOrEqual(0.0, $peakAt);
        $this->assertLessThan(60.0, $peakAt, 'peakAtSecond must be relative seconds, not absolute microtime');
    }

    /**
     * @test
     * Mata varios mutantes en línea 147 (`$coresInUse += $threadAllocations
     * [name] ?? 1`): IncrementInteger ?? 2, DecrementInteger ?? 0, Coalesce
     * left/right swap, Assignment += → =, PlusEqual += → -=. Con dos jobs
     * con costes distintos en threadAllocations, real suma 3+5=8; los
     * mutantes producen 5, 0, 1, 5 (último valor), o un negativo.
     *
     * Mata también la línea 145 ($coresInUse=0 → -1) y la 146 (Foreach_→[])
     * que dejarían el contador en -1 / 0.
     *
     * Mata la línea 149 MethodCallRemoval (recordCoresSample) y
     * UnwrapArrayKeys (running array completo) verificando coresPeakJobs.
     */
    public function tick_records_cores_in_use_summing_thread_allocations_per_job(): void
    {
        $sampler = new FakeMemorySampler([['phpunit' => 100, 'phpstan' => 100]]);
        $options = $this->options(null, true);
        $handler = $this->buildHandlerWithThreadAllocations(
            $options,
            $sampler,
            ['phpunit' => 3, 'phpstan' => 5]
        );

        $handler->setup([$this->jobWithoutMemory('phpunit')]);
        $handler->tick([
            'phpunit' => $this->runningEntry(101),
            'phpstan' => $this->runningEntry(202),
        ]);

        $flowResult = $this->makeFlowResult();
        $handler->attachStats($flowResult);

        $stats = $flowResult->getMemoryStats();
        $this->assertNotNull($stats);
        $this->assertSame(8, $stats->getCoresPeak(), 'coresPeak = 3 + 5 (sum of thread allocations)');
        $this->assertSame(['phpunit', 'phpstan'], $stats->getCoresPeakJobs());
    }

    /**
     * @test
     * Mata específicamente IncrementInteger en línea 147 (`?? 1` → `?? 2`):
     * un job que NO está en threadAllocations debe contar como 1 core, no 2.
     */
    public function tick_uses_default_one_core_for_jobs_not_in_thread_allocations(): void
    {
        $sampler = new FakeMemorySampler([['phpunit' => 100]]);
        $options = $this->options(null, true);
        $handler = $this->buildHandlerWithThreadAllocations(
            $options,
            $sampler,
            [] // map vacío: el job 'phpunit' usa el fallback
        );

        $handler->setup([$this->jobWithoutMemory('phpunit')]);
        $handler->tick(['phpunit' => $this->runningEntry(101)]);

        $flowResult = $this->makeFlowResult();
        $handler->attachStats($flowResult);

        $this->assertSame(1, $flowResult->getMemoryStats()->getCoresPeak());
    }

    /** @test */
    public function should_kill_returns_false_when_disabled(): void
    {
        $sampler = new FakeMemorySampler([['phpunit' => 9999]]);
        $options = $this->options(new MemoryBudgetConfiguration(100, 200));
        $handler = $this->buildHandlerWithSampler($options, $sampler, true);

        // disabled=true → setup returns false (no thresholds, no stats)
        $handler->setup([$this->jobWithoutMemory('phpcs')]);
        $this->assertFalse($handler->shouldKill());
    }

    /** @test */
    public function should_kill_returns_false_when_handler_was_not_set_up(): void
    {
        $handler = $this->buildHandler($this->options());

        $this->assertFalse($handler->shouldKill());
    }

    /** @test */
    public function should_kill_returns_true_when_memory_peak_crosses_fail_above(): void
    {
        $sampler = new FakeMemorySampler([['phpunit' => 1500]]);
        $options = $this->options(new MemoryBudgetConfiguration(800, 1200));
        $handler = $this->buildHandlerWithSampler($options, $sampler);

        $handler->setup([$this->jobWithoutMemory('phpcs')]);
        $handler->tick(['phpunit' => $this->runningEntry(101)]);

        $this->assertTrue($handler->shouldKill());
    }

    /** @test */
    public function should_kill_returns_false_while_memory_peak_stays_below_fail_above(): void
    {
        $sampler = new FakeMemorySampler([['phpunit' => 900]]);
        $options = $this->options(new MemoryBudgetConfiguration(800, 1200));
        $handler = $this->buildHandlerWithSampler($options, $sampler);

        $handler->setup([$this->jobWithoutMemory('phpcs')]);
        $handler->tick(['phpunit' => $this->runningEntry(101)]);

        $this->assertFalse($handler->shouldKill());
    }

    /**
     * @test
     * Mata el mutante ReturnRemoval en línea 168: el early return cuando
     * evaluator es null debe respetar el contrato "no tocar los results".
     * Sin él, jobs con memoryReserve declarado contaminarían el resultado
     * con `withMemoryReserved`. Pasamos un job CON reserve para hacer la
     * diferencia observable.
     */
    public function enrich_results_returns_input_unchanged_when_handler_inactive(): void
    {
        $handler = $this->buildHandler($this->options());
        $original = new JobResult('phpcs', true, '', '50ms');
        $jobWithReserve = $this->jobWithReserve('phpcs', 256);

        $enriched = $handler->enrichResults([$original], [$jobWithReserve]);

        $this->assertSame([$original], $enriched);
        $this->assertNull($enriched[0]->getMemoryReserved());
    }

    /**
     * @test
     * Mata el mutante ArrayOneItem en línea 180 (`return $enriched`): si la
     * lista se trunca al primer elemento, se pierden los resultados de los
     * demás jobs.
     */
    public function enrich_results_returns_every_enriched_result_when_active(): void
    {
        $sampler = new FakeMemorySampler([['phpunit' => 700, 'phpstan' => 300]]);
        $options = $this->options(new MemoryBudgetConfiguration(800, 1500));
        $handler = $this->buildHandlerWithSampler($options, $sampler);

        $jobs = [
            $this->jobWithMemory('phpunit', 512, MemoryThreshold::fromInt(600)),
            $this->jobWithMemory('phpstan', 256, null),
        ];
        $handler->setup($jobs);
        $handler->tick([
            'phpunit' => $this->runningEntry(101),
            'phpstan' => $this->runningEntry(202),
        ]);

        $results = [
            new JobResult('phpunit', true, '', '50ms'),
            new JobResult('phpstan', true, '', '20ms'),
        ];
        $enriched = $handler->enrichResults($results, $jobs);

        $this->assertCount(2, $enriched);
        $this->assertSame('phpunit', $enriched[0]->getJobName());
        $this->assertSame('phpstan', $enriched[1]->getJobName());
        $this->assertSame(512, $enriched[0]->getMemoryReserved());
        $this->assertSame(256, $enriched[1]->getMemoryReserved());
    }

    /** @test */
    public function enrich_results_attaches_peak_reserve_and_threshold_for_the_matching_job(): void
    {
        $sampler = new FakeMemorySampler([['phpunit' => 700]]);
        $options = $this->options(new MemoryBudgetConfiguration(800, 1500));
        $handler = $this->buildHandlerWithSampler($options, $sampler);

        $job = $this->jobWithMemory('phpunit', 512, MemoryThreshold::fromInt(600));
        $handler->setup([$job]);
        $handler->tick(['phpunit' => $this->runningEntry(101)]);

        $result = new JobResult('phpunit', true, '', '50ms');
        $enriched = $handler->enrichResults([$result], [$job]);

        $this->assertCount(1, $enriched);
        $this->assertSame(700, $enriched[0]->getMemoryPeak());
        $this->assertSame(512, $enriched[0]->getMemoryReserved());
        $this->assertTrue(
            $enriched[0]->isMemoryWarned(),
            'peak 700 > short-form threshold 600 must mark the result as warned'
        );
        $this->assertSame(600, $enriched[0]->getConfiguredMemoryWarn());
    }

    /** @test */
    public function enrich_results_skips_threshold_when_disabled_even_if_job_declares_one(): void
    {
        $sampler = new FakeMemorySampler([['phpunit' => 9000]]);
        $options = $this->options(new MemoryBudgetConfiguration(800, 1500));

        $job = $this->jobWithMemory('phpunit', null, MemoryThreshold::fromInt(100));
        // disabled=true: threshold del job no debe surgir en el resultado.
        $handler = $this->buildHandlerWithSampler($options, $sampler, true);
        $handler->setup([$job]);
        $handler->tick(['phpunit' => $this->runningEntry(101)]);

        $result = new JobResult('phpunit', true, '', '50ms');
        $enriched = $handler->enrichResults([$result], [$job]);

        $this->assertSame(9000, $enriched[0]->getMemoryPeak(), 'peak still recorded for stats');
        $this->assertSame(JobResult::MEMORY_THRESHOLD_NONE, $enriched[0]->getMemoryThresholdState());
        $this->assertNull($enriched[0]->getConfiguredMemoryWarn());
    }

    /** @test */
    public function enrich_results_passes_through_results_without_a_matching_job(): void
    {
        $sampler = new FakeMemorySampler([['phpunit' => 700]]);
        $options = $this->options(new MemoryBudgetConfiguration(800, 1500));
        $handler = $this->buildHandlerWithSampler($options, $sampler);

        $job = $this->jobWithMemory('phpunit', 512, MemoryThreshold::fromInt(600));
        $handler->setup([$job]);
        $handler->tick(['phpunit' => $this->runningEntry(101)]);

        $orphan = new JobResult('phpcs_extra', true, '', '10ms');
        $enriched = $handler->enrichResults([$orphan], [$job]);

        $this->assertNull($enriched[0]->getMemoryPeak());
        $this->assertNull($enriched[0]->getMemoryReserved());
    }

    /**
     * @test
     * Per-job FAIL by memory threshold flips OK→KO when the tool itself passed,
     * symmetric with the time-budget contract enforced in FlowExecutor (RAT-006
     * mirror for memory).
     */
    public function enrich_results_marks_job_failed_when_peak_crosses_fail_above_per_job(): void
    {
        $sampler = new FakeMemorySampler([['phpunit' => 2200]]);
        $options = $this->options();
        $handler = $this->buildHandlerWithSampler($options, $sampler);

        $threshold = MemoryThreshold::fromArray(
            ['warn-above' => 1000, 'fail-above' => 2000],
            new \Wtyd\GitHooks\Configuration\ValidationResult(),
            'phpunit'
        );
        $job = $this->jobWithMemory('phpunit', null, $threshold);
        $handler->setup([$job]);
        $handler->tick(['phpunit' => $this->runningEntry(101)]);

        $result = new JobResult('phpunit', true, '', '50ms');
        $enriched = $handler->enrichResults([$result], [$job]);

        $this->assertTrue(
            $enriched[0]->isMemoryFailed(),
            'peak 2200 > fail-above 2000 must mark the threshold state as failed'
        );
        $this->assertFalse(
            $enriched[0]->isSuccess(),
            'per-job fail-above crossed: success must flip OK→KO'
        );
    }

    /**
     * @test
     * Crossing only `warn-above` is informational and must NOT flip success.
     * This is the boundary that distinguishes warn from fail per-job.
     */
    public function enrich_results_keeps_job_passing_when_peak_only_crosses_warn_above(): void
    {
        $sampler = new FakeMemorySampler([['phpunit' => 1500]]);
        $options = $this->options();
        $handler = $this->buildHandlerWithSampler($options, $sampler);

        $threshold = MemoryThreshold::fromArray(
            ['warn-above' => 1000, 'fail-above' => 2000],
            new \Wtyd\GitHooks\Configuration\ValidationResult(),
            'phpunit'
        );
        $job = $this->jobWithMemory('phpunit', null, $threshold);
        $handler->setup([$job]);
        $handler->tick(['phpunit' => $this->runningEntry(101)]);

        $result = new JobResult('phpunit', true, '', '50ms');
        $enriched = $handler->enrichResults([$result], [$job]);

        $this->assertTrue(
            $enriched[0]->isMemoryWarned(),
            'peak 1500 between warn 1000 and fail 2000 must mark warned'
        );
        $this->assertTrue(
            $enriched[0]->isSuccess(),
            'warn-above alone is informational — must not flip success'
        );
    }

    /**
     * @test
     * When the tool already failed, a memory threshold crossing is informational
     * only — success stays false but the original failure mode (exitCode/output)
     * is preserved. Mirrors RAT-006 from time-budget for symmetry.
     */
    public function enrich_results_preserves_existing_failure_when_threshold_also_fails(): void
    {
        $sampler = new FakeMemorySampler([['phpunit' => 2500]]);
        $options = $this->options();
        $handler = $this->buildHandlerWithSampler($options, $sampler);

        $threshold = MemoryThreshold::fromArray(
            ['warn-above' => 1000, 'fail-above' => 2000],
            new \Wtyd\GitHooks\Configuration\ValidationResult(),
            'phpunit'
        );
        $job = $this->jobWithMemory('phpunit', null, $threshold);
        $handler->setup([$job]);
        $handler->tick(['phpunit' => $this->runningEntry(101)]);

        // Tool already failed (success=false). Memory threshold also crosses.
        $result = new JobResult('phpunit', false, 'tool error', '50ms');
        $enriched = $handler->enrichResults([$result], [$job]);

        $this->assertFalse($enriched[0]->isSuccess(), 'pre-existing failure must remain false');
        $this->assertTrue(
            $enriched[0]->isMemoryFailed(),
            'memory threshold state must still surface for reporting'
        );
        $this->assertSame('tool error', $enriched[0]->getOutput(), 'original output preserved');
    }

    /** @test */
    public function attach_stats_is_a_noop_when_handler_inactive(): void
    {
        $handler = $this->buildHandler($this->options());
        $flowResult = $this->makeFlowResult();

        $handler->attachStats($flowResult);

        $this->assertNull($flowResult->getMemoryBudgetState());
        $this->assertNull($flowResult->getMemoryStats());
    }

    /** @test */
    public function attach_stats_sets_budget_state_when_active_and_not_disabled(): void
    {
        $sampler = new FakeMemorySampler([['phpunit' => 1000]]);
        $options = $this->options(new MemoryBudgetConfiguration(800, 1500));
        $handler = $this->buildHandlerWithSampler($options, $sampler);

        $handler->setup([$this->jobWithoutMemory('phpcs')]);
        $handler->tick(['phpunit' => $this->runningEntry(101)]);

        $flowResult = $this->makeFlowResult();
        $handler->attachStats($flowResult);

        $state = $flowResult->getMemoryBudgetState();
        $this->assertNotNull($state);
        $this->assertSame(1000, $state->getPeakObserved());
        $this->assertTrue($state->isWarned(), 'peak 1000 > warn 800');
        $this->assertFalse($state->isFailed(), 'peak 1000 < fail 1500');
    }

    /** @test */
    public function attach_stats_does_not_set_budget_state_when_disabled(): void
    {
        $sampler = new FakeMemorySampler([['phpunit' => 1000]]);
        $options = $this->options(new MemoryBudgetConfiguration(800, 1500), true);
        $handler = $this->buildHandlerWithSampler($options, $sampler, true);

        $handler->setup([$this->jobWithoutMemory('phpcs')]);

        $flowResult = $this->makeFlowResult();
        $handler->attachStats($flowResult);

        // Con disabled=true + sólo --stats, el budget state se omite y solo
        // se publica el bloque de stats (no el budget gate).
        $this->assertNull($flowResult->getMemoryBudgetState());
        $this->assertNotNull($flowResult->getMemoryStats());
    }

    /** @test */
    public function attach_stats_sets_memory_stats_when_stats_requested(): void
    {
        $sampler = new FakeMemorySampler([['phpunit' => 700]]);
        $options = $this->options(null, true);
        $handler = $this->buildHandlerWithSampler($options, $sampler);

        $handler->setup([$this->jobWithoutMemory('phpcs')]);
        $handler->tick(['phpunit' => $this->runningEntry(101)]);

        $flowResult = $this->makeFlowResult();
        $handler->attachStats($flowResult);

        $stats = $flowResult->getMemoryStats();
        $this->assertNotNull($stats);
        $this->assertSame(700, $stats->getMemoryPeak());
    }

    /** @test */
    public function attach_stats_does_not_set_memory_stats_when_stats_not_requested(): void
    {
        $sampler = new FakeMemorySampler([['phpunit' => 700]]);
        $options = $this->options(new MemoryBudgetConfiguration(800, 1500), false);
        $handler = $this->buildHandlerWithSampler($options, $sampler);

        $handler->setup([$this->jobWithoutMemory('phpcs')]);
        $handler->tick(['phpunit' => $this->runningEntry(101)]);

        $flowResult = $this->makeFlowResult();
        $handler->attachStats($flowResult);

        $this->assertNotNull($flowResult->getMemoryBudgetState(), 'budget state still attached');
        $this->assertNull($flowResult->getMemoryStats(), 'stats block omitted without --stats');
    }

    // Helpers

    private function options(
        ?MemoryBudgetConfiguration $budget = null,
        bool $stats = false
    ): OptionsConfiguration {
        return new OptionsConfiguration(
            false,            // failFast
            2,                // processes
            null,             // mainBranch
            'full',           // fastBranchFallback
            '',               // executablePrefix
            [],               // reports
            null,             // timeBudget
            $budget,          // memoryBudget
            'fifo',           // allocator
            $stats            // stats
        );
    }

    private function buildHandler(
        OptionsConfiguration $options,
        bool $disabled = false
    ): FlowMemoryHandler {
        return $this->buildHandlerWithSampler($options, new FakeMemorySampler(), $disabled);
    }

    private function buildHandlerWithSampler(
        OptionsConfiguration $options,
        MemorySampler $sampler,
        bool $disabled = false
    ): FlowMemoryHandler {
        return $this->buildHandlerWithThreadAllocations($options, $sampler, [], $disabled);
    }

    /**
     * @param array<string, int> $threadAllocations
     */
    private function buildHandlerWithThreadAllocations(
        OptionsConfiguration $options,
        MemorySampler $sampler,
        array $threadAllocations,
        bool $disabled = false
    ): FlowMemoryHandler {
        return new class ($options, $disabled, $sampler, $threadAllocations) extends FlowMemoryHandler {
            private MemorySampler $injected;

            /** @var string[] */
            public array $__capturedWarnings = [];

            /**
             * @param array<string, int> $threadAllocations
             */
            public function __construct(
                OptionsConfiguration $options,
                bool $disabled,
                MemorySampler $sampler,
                array $threadAllocations
            ) {
                parent::__construct($options, $disabled, microtime(true), $threadAllocations);
                $this->injected = $sampler;
            }

            protected function buildSampler(): MemorySampler
            {
                return $this->injected;
            }

            protected function emitWarning(string $message): void
            {
                $this->__capturedWarnings[] = $message;
            }
        };
    }

    private function jobWithoutMemory(string $name): JobAbstract
    {
        $job = Mockery::mock(JobAbstract::class);
        $job->shouldReceive('getName')->andReturn($name);
        $job->shouldReceive('getMemoryReserve')->andReturn(null);
        $job->shouldReceive('getMemoryThreshold')->andReturn(null);
        return $job;
    }

    private function jobWithThreshold(string $name, MemoryThreshold $threshold): JobAbstract
    {
        $job = Mockery::mock(JobAbstract::class);
        $job->shouldReceive('getName')->andReturn($name);
        $job->shouldReceive('getMemoryReserve')->andReturn(null);
        $job->shouldReceive('getMemoryThreshold')->andReturn($threshold);
        return $job;
    }

    private function jobWithReserve(string $name, int $reserve): JobAbstract
    {
        $job = Mockery::mock(JobAbstract::class);
        $job->shouldReceive('getName')->andReturn($name);
        $job->shouldReceive('getMemoryReserve')->andReturn($reserve);
        $job->shouldReceive('getMemoryThreshold')->andReturn(null);
        return $job;
    }

    private function jobWithMemory(string $name, ?int $reserve, ?MemoryThreshold $threshold): JobAbstract
    {
        $job = Mockery::mock(JobAbstract::class);
        $job->shouldReceive('getName')->andReturn($name);
        $job->shouldReceive('getMemoryReserve')->andReturn($reserve);
        $job->shouldReceive('getMemoryThreshold')->andReturn($threshold);
        return $job;
    }

    /**
     * @return array{process: Process, job: JobAbstract, start: float}
     */
    private function runningEntry(?int $pid): array
    {
        $process = Mockery::mock(Process::class);
        $process->shouldReceive('getPid')->andReturn($pid);

        return [
            'process' => $process,
            'job'     => $this->jobWithoutMemory('placeholder'),
            'start'   => microtime(true),
        ];
    }

    private function makeFlowResult(): FlowResult
    {
        return new FlowResult('qa', [], '0.00s');
    }
}
