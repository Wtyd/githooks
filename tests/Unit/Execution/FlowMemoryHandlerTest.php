<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use Mockery;
use PHPUnit\Framework\TestCase;
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
class FlowMemoryHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    // ========================================================================
    // setup()
    // ========================================================================

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
        $this->assertStringContainsString('Memory budget disabled', $warnings[0]);
        $this->assertStringContainsString('platform unsupported (test)', $warnings[0]);
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

    // ========================================================================
    // tick()
    // ========================================================================

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

    // ========================================================================
    // shouldKill()
    // ========================================================================

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

    // ========================================================================
    // enrichResults()
    // ========================================================================

    /** @test */
    public function enrich_results_returns_input_unchanged_when_handler_inactive(): void
    {
        $handler = $this->buildHandler($this->options());
        $original = new JobResult('phpcs', true, '', '50ms');

        $enriched = $handler->enrichResults([$original], []);

        $this->assertSame([$original], $enriched);
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

    // ========================================================================
    // attachStats()
    // ========================================================================

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

    // ========================================================================
    // Helpers
    // ========================================================================

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
        return new class ($options, $disabled, $sampler) extends FlowMemoryHandler {
            private MemorySampler $injected;

            /** @var string[] */
            public array $__capturedWarnings = [];

            public function __construct(
                OptionsConfiguration $options,
                bool $disabled,
                MemorySampler $sampler
            ) {
                parent::__construct($options, $disabled, microtime(true), []);
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
