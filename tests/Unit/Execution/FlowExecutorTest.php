<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use Tests\Doubles\GitStagerFake;
use Tests\Doubles\OutputHandlerSpy;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowPlan;
use Wtyd\GitHooks\Execution\ThreadCapability;
use Wtyd\GitHooks\Jobs\CustomJob;
use Wtyd\GitHooks\Jobs\ParallelLintJob;
use Wtyd\GitHooks\Jobs\ParatestJob;
use Wtyd\GitHooks\Jobs\PhpcsJob;
use Wtyd\GitHooks\Jobs\PhpstanJob;
use Wtyd\GitHooks\Output\NullOutputHandler;
use Wtyd\GitHooks\Output\OutputHandler;

/**
 * Tests targeting escaped mutants in FlowExecutor:
 * dry-run, sequential, buildResult, formatTime, parallel, ignoreErrorsOnExit.
 */
class FlowExecutorTest extends TestCase
{
    // ========================================================================
    // Dry-run mode
    // ========================================================================

    /** @test */
    public function dry_run_returns_all_jobs_as_success()
    {
        $executor = new FlowExecutor(new NullOutputHandler());

        $jobs = [
            new CustomJob(new JobConfiguration('job_a', 'custom', ['script' => 'echo a'])),
            new CustomJob(new JobConfiguration('job_b', 'custom', ['script' => 'echo b'])),
        ];

        $plan = new FlowPlan('test', $jobs, new OptionsConfiguration());
        $result = $executor->execute($plan, true);

        $this->assertTrue($result->isSuccess());
        $this->assertCount(2, $result->getJobResults());
        $this->assertSame('0ms', $result->getTotalTime());
        $this->assertSame(0, $result->getPeakEstimatedThreads());

        foreach ($result->getJobResults() as $jr) {
            $this->assertTrue($jr->isSuccess());
            $this->assertSame('0ms', $jr->getExecutionTime());
            $this->assertNotNull($jr->getCommand());
            // Kills FalseValue mutant on the dry-run JobResult fixApplied
            // flag: dry-run never modifies the working tree, so isFixApplied
            // must be false regardless of the underlying job type.
            $this->assertFalse($jr->isFixApplied());
            $this->assertFalse($jr->isSkipped());
        }
    }

    /** @test */
    public function dry_run_calls_onJobDryRun_for_each_job()
    {
        $handler = $this->createMock(OutputHandler::class);
        $handler->expects($this->exactly(2))
            ->method('onJobDryRun');
        // Dry-run emits no progress events: onFlowStart/flush are only meaningful
        // when real execution measures progress. Calling them would make
        // ProgressOutputHandler emit a bogus "Done. 0/N completed." on stderr.
        $handler->expects($this->never())->method('onFlowStart');
        $handler->expects($this->never())->method('flush');

        $executor = new FlowExecutor($handler);

        $jobs = [
            new CustomJob(new JobConfiguration('job_a', 'custom', ['script' => 'echo a'])),
            new CustomJob(new JobConfiguration('job_b', 'custom', ['script' => 'echo b'])),
        ];

        $plan = new FlowPlan('test', $jobs, new OptionsConfiguration());
        $executor->execute($plan, true);
    }

    // ========================================================================
    // Sequential execution
    // ========================================================================

    /** @test */
    public function sequential_single_job_success()
    {
        $executor = new FlowExecutor(new NullOutputHandler());

        $job = new CustomJob(new JobConfiguration('ok', 'custom', ['script' => 'echo ok']));
        $plan = new FlowPlan('test', [$job], new OptionsConfiguration(false, 1));

        $result = $executor->execute($plan);

        $this->assertTrue($result->isSuccess());
        $this->assertCount(1, $result->getJobResults());
        $this->assertTrue($result->getJobResults()[0]->isSuccess());
    }

    /** @test */
    public function sequential_single_job_failure()
    {
        $executor = new FlowExecutor(new NullOutputHandler());

        $job = new CustomJob(new JobConfiguration('fail', 'custom', ['script' => 'exit 2']));
        $plan = new FlowPlan('test', [$job], new OptionsConfiguration(false, 1));

        $result = $executor->execute($plan);

        $this->assertFalse($result->isSuccess());
        $this->assertFalse($result->getJobResults()[0]->isSuccess());
    }

    /** @test */
    public function sequential_fail_fast_stops_after_first_failure()
    {
        $executor = new FlowExecutor(new NullOutputHandler());

        $jobs = [
            new CustomJob(new JobConfiguration('fail', 'custom', ['script' => 'exit 1'])),
            new CustomJob(new JobConfiguration('never', 'custom', ['script' => 'echo never'])),
        ];

        $plan = new FlowPlan('test', $jobs, new OptionsConfiguration(true, 1));
        $result = $executor->execute($plan);

        $names = array_map(fn($r) => $r->getJobName(), $result->getJobResults());
        $this->assertContains('fail', $names);
        // 'never' appears in results as a skipped entry so consumers of structured
        // formats (JSON/JUnit/SARIF) see the full plan, not just executed jobs.
        $this->assertContains('never', $names);

        $neverResult = null;
        foreach ($result->getJobResults() as $jr) {
            if ($jr->getJobName() === 'never') {
                $neverResult = $jr;
            }
        }
        $this->assertNotNull($neverResult);
        $this->assertTrue($neverResult->isSkipped());
    }

    /** @test */
    public function sequential_fail_fast_reports_skipped_jobs()
    {
        $handler = $this->createMock(OutputHandler::class);
        $handler->expects($this->once())
            ->method('onJobSkipped')
            ->with($this->anything(), 'skipped by fail-fast');

        $executor = new FlowExecutor($handler);

        $jobs = [
            new CustomJob(new JobConfiguration('fail', 'custom', ['script' => 'exit 1'])),
            new CustomJob(new JobConfiguration('skipped', 'custom', ['script' => 'echo skipped'])),
        ];

        $plan = new FlowPlan('test', $jobs, new OptionsConfiguration(true, 1));
        $executor->execute($plan);
    }

    /** @test */
    public function sequential_fail_fast_only_reports_jobs_after_failure_when_failure_is_in_the_middle()
    {
        // Kills FalseValue mutant on `$found = false;` initialisation in
        // reportSkipped: with `$found = true;` the loop would also report
        // jobs BEFORE the failed one as skipped. The asymmetric layout
        // [ok, fail, third] forces that branch.
        $skippedJobs = [];
        $handler = $this->createMock(OutputHandler::class);
        $handler->method('onJobSkipped')
            ->willReturnCallback(function (string $name, string $reason) use (&$skippedJobs): void {
                $skippedJobs[] = $name;
            });

        $executor = new FlowExecutor($handler);

        $jobs = [
            new CustomJob(new JobConfiguration('ok', 'custom', ['script' => 'echo ok'])),
            new CustomJob(new JobConfiguration('fail', 'custom', ['script' => 'exit 1'])),
            new CustomJob(new JobConfiguration('third', 'custom', ['script' => 'echo never'])),
        ];

        $plan = new FlowPlan('test', $jobs, new OptionsConfiguration(true, 1));
        $result = $executor->execute($plan);

        // Only the third job (after the failure) must be reported as skipped.
        $this->assertSame(['third'], $skippedJobs);

        $resultsByName = [];
        foreach ($result->getJobResults() as $jr) {
            $resultsByName[$jr->getJobName()] = $jr;
        }
        $this->assertArrayHasKey('ok', $resultsByName);
        $this->assertArrayHasKey('fail', $resultsByName);
        $this->assertArrayHasKey('third', $resultsByName);
        $this->assertFalse($resultsByName['ok']->isSkipped(), 'pre-failure job must not be skipped');
        $this->assertFalse($resultsByName['fail']->isSkipped(), 'failed job is failure, not skip');
        $this->assertTrue($resultsByName['third']->isSkipped(), 'post-failure job must be skipped');
    }

    /** @test */
    public function sequential_without_fail_fast_runs_all_jobs()
    {
        $executor = new FlowExecutor(new NullOutputHandler());

        $jobs = [
            new CustomJob(new JobConfiguration('fail', 'custom', ['script' => 'exit 1'])),
            new CustomJob(new JobConfiguration('ok', 'custom', ['script' => 'echo ok'])),
        ];

        $plan = new FlowPlan('test', $jobs, new OptionsConfiguration(false, 1));
        $result = $executor->execute($plan);

        $this->assertCount(2, $result->getJobResults());
        $names = array_map(fn($r) => $r->getJobName(), $result->getJobResults());
        $this->assertContains('fail', $names);
        $this->assertContains('ok', $names);
    }

    // ========================================================================
    // buildResult: ignoreErrorsOnExit
    // ========================================================================

    /** @test */
    public function ignore_errors_on_exit_treats_failure_as_success()
    {
        $executor = new FlowExecutor(new NullOutputHandler());

        // CustomJob with ignoreErrorsOnExit — use a config with the flag
        $job = new CustomJob(new JobConfiguration('ignore', 'custom', [
            'script' => 'exit 1',
            'ignore-errors-on-exit' => true,
        ]));

        $plan = new FlowPlan('test', [$job], new OptionsConfiguration(false, 1));
        $result = $executor->execute($plan);

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->getJobResults()[0]->isSuccess());
    }

    // ========================================================================
    // buildResult: exit code null (killed process)
    // ========================================================================

    /** @test */
    public function success_job_exit_code_zero()
    {
        $executor = new FlowExecutor(new NullOutputHandler());

        $job = new CustomJob(new JobConfiguration('ok', 'custom', ['script' => 'echo ok && exit 0']));
        $plan = new FlowPlan('test', [$job], new OptionsConfiguration(false, 1));
        $result = $executor->execute($plan);

        $this->assertTrue($result->getJobResults()[0]->isSuccess());
    }

    // ========================================================================
    // formatTime boundaries
    // ========================================================================

    /** @test */
    public function fast_job_shows_milliseconds()
    {
        $executor = new FlowExecutor(new NullOutputHandler());

        $job = new CustomJob(new JobConfiguration('fast', 'custom', ['script' => 'true']));
        $plan = new FlowPlan('test', [$job], new OptionsConfiguration(false, 1));
        $result = $executor->execute($plan);

        // Should be in milliseconds format (Nms)
        $this->assertMatchesRegularExpression('/^\d+ms$/', $result->getJobResults()[0]->getExecutionTime());
    }

    /**
     * Decision-table test for FlowExecutor::formatTime boundaries.
     *
     * Factors:
     *  - $seconds < 1   → "Nms" (round to milliseconds)
     *  - $seconds < 60  → "X.YYs" (number_format 2 decimals)
     *  - $seconds >= 60 → "{Mm Ss}" (floor minutes, integer seconds)
     *
     * Frontiers (AVL):
     *  - 0.999 vs 1.0       (< 1 vs == 1) — kills LessThan mutation
     *  - 59.99 vs 60.0      (< 60 vs == 60) — kills second branch boundary
     *  - 90, 119, 120       — kills DecrementInteger (`/60` vs `/59`),
     *                         RoundingFamily (`floor` vs `ceil`/`round`) and
     *                         CastInt (integer seconds in interpolation).
     *
     * @test
     * @dataProvider formatTimeBoundaries
     */
    public function format_time_returns_expected_string_for_boundary(float $seconds, string $expected): void
    {
        $executor = new FlowExecutor(new NullOutputHandler());
        $reflection = new \ReflectionMethod(FlowExecutor::class, 'formatTime');
        $reflection->setAccessible(true);

        $this->assertSame($expected, $reflection->invoke($executor, $seconds));
    }

    /**
     * Decision-table test for FlowExecutor::buildTimeBudgetState.
     *
     * Factors:
     *  - sum of durations (skipped jobs excluded by `continue`)
     *  - failAfter threshold (null = disabled, otherwise sum >= failAfter ⇒ failed)
     *  - warnAfter threshold (null = disabled, otherwise sum >= warnAfter ⇒ warned,
     *    BUT only when not failed: !$failed && $warnAfter !== null && $sum >= $warnAfter)
     *
     * Mutants killed:
     *  - L782 Continue_ (continue → break): skipped jobs in middle of list still
     *    leave subsequent durations summed.
     *  - L784 Assignment (`+=` → `=`): exact total of multiple non-skipped durations.
     *  - L790 LogicalAnd: when failed=true AND warnAfter is reached, warned must
     *    remain false (the !$failed guard wins).
     *
     * @test
     */
    public function build_time_budget_state_sums_only_non_skipped_durations(): void
    {
        $executor = new FlowExecutor(new NullOutputHandler());
        $reflection = new \ReflectionMethod(FlowExecutor::class, 'buildTimeBudgetState');
        $reflection->setAccessible(true);

        $budget = new \Wtyd\GitHooks\Configuration\TimeBudgetConfiguration(10, 20);

        // Factor coverage: 4 results of which 1 is skipped (in the middle).
        // Sum must be 1.0 + 2.0 + 4.0 = 7.0 (the skipped 100.0 is dropped via
        // `continue`; mutating to `break` would stop after the skipped entry,
        // dropping the trailing 4.0 and producing sum=3.0). Killing
        // `+=` → `=` requires summing more than two non-skipped durations.
        $r1 = $this->makeJobResult('a', 1.0, false);
        $r2 = $this->makeJobResult('b', 2.0, false);
        $r3 = $this->makeJobResult('skipped', 100.0, true);
        $r4 = $this->makeJobResult('d', 4.0, false);

        /** @var \Wtyd\GitHooks\Execution\TimeBudgetState $state */
        $state = $reflection->invoke($executor, $budget, [$r1, $r2, $r3, $r4]);
        $this->assertNotNull($state);
        $this->assertEqualsWithDelta(7.0, $state->getTotalJobDuration(), 0.001);
        $this->assertFalse($state->isFailed());
        $this->assertFalse($state->isWarned()); // 7 < 10
    }

    /**
     * @test
     */
    public function build_time_budget_state_warned_true_when_sum_in_warn_band(): void
    {
        $executor = new FlowExecutor(new NullOutputHandler());
        $reflection = new \ReflectionMethod(FlowExecutor::class, 'buildTimeBudgetState');
        $reflection->setAccessible(true);

        $budget = new \Wtyd\GitHooks\Configuration\TimeBudgetConfiguration(10, 20);

        $r1 = $this->makeJobResult('a', 12.0, false);

        $state = $reflection->invoke($executor, $budget, [$r1]);
        $this->assertNotNull($state);
        $this->assertFalse($state->isFailed()); // 12 < 20
        $this->assertTrue($state->isWarned());  // 12 >= 10
    }

    /**
     * @test
     * Kills L790 LogicalAnd: when sum >= failAfter AND sum >= warnAfter, the
     * `!$failed` guard must prevent warned from being set. Mutating to
     * `(!$failed || $warnAfter !== null) && ...` would let `warned` be true.
     */
    public function build_time_budget_state_warned_false_when_failed(): void
    {
        $executor = new FlowExecutor(new NullOutputHandler());
        $reflection = new \ReflectionMethod(FlowExecutor::class, 'buildTimeBudgetState');
        $reflection->setAccessible(true);

        $budget = new \Wtyd\GitHooks\Configuration\TimeBudgetConfiguration(10, 20);

        $r1 = $this->makeJobResult('a', 25.0, false); // exceeds both warn and fail

        $state = $reflection->invoke($executor, $budget, [$r1]);
        $this->assertNotNull($state);
        $this->assertTrue($state->isFailed());   // 25 >= 20
        $this->assertFalse($state->isWarned());  // failed wins
    }

    /**
     * @test
     */
    public function build_time_budget_state_returns_null_when_budget_is_null(): void
    {
        $executor = new FlowExecutor(new NullOutputHandler());
        $reflection = new \ReflectionMethod(FlowExecutor::class, 'buildTimeBudgetState');
        $reflection->setAccessible(true);

        $this->assertNull($reflection->invoke($executor, null, []));
    }

    private function makeJobResult(string $name, float $durationSeconds, bool $skipped): \Wtyd\GitHooks\Execution\JobResult
    {
        if ($skipped) {
            return \Wtyd\GitHooks\Execution\JobResult::skipped($name, 'custom', 'skip', []);
        }
        return new \Wtyd\GitHooks\Execution\JobResult(
            $name,
            true,
            'output',
            sprintf('%.2fs', $durationSeconds),
            false,
            null,
            'custom',
            0,
            [],
            false,
            null,
            null,
            null,
            $durationSeconds
        );
    }

    /**
     * @return array<string, array{0: float, 1: string}>
     */
    public function formatTimeBoundaries(): array
    {
        return [
            'sub-millisecond rounds to 0ms'    => [0.0001,  '0ms'],
            'just below 1s → 999ms'            => [0.999,   '999ms'],
            'exactly 1s → "1.00s" (not 1000ms)' => [1.0,     '1.00s'],
            'fractional seconds < 60'          => [12.345,  '12.35s'],
            'just below 60s → "59.99s"'        => [59.99,   '59.99s'],
            'exactly 60s → "1m 0s"'            => [60.0,    '1m 0s'],
            '90s → "1m 30s" (kills RoundingFamily floor→ceil/round)' => [90.0, '1m 30s'],
            '119s → "1m 59s" (kills DecrementInteger /60→/59)'       => [119.0, '1m 59s'],
            '120s → "2m 0s"'                  => [120.0,  '2m 0s'],
            'fractional minutes 90.7s → "1m 30s" (kills CastInt on $secs)' => [90.7, '1m 30s'],
        ];
    }

    // ========================================================================
    // Parallel: two passing jobs
    // ========================================================================

    /** @test */
    public function parallel_two_passing_jobs_both_in_results()
    {
        $executor = new FlowExecutor(new NullOutputHandler());

        $jobs = [
            new CustomJob(new JobConfiguration('job_a', 'custom', ['script' => 'echo a'])),
            new CustomJob(new JobConfiguration('job_b', 'custom', ['script' => 'echo b'])),
        ];

        $plan = new FlowPlan('test', $jobs, new OptionsConfiguration(false, 2));
        $result = $executor->execute($plan);

        $this->assertTrue($result->isSuccess());
        $this->assertCount(2, $result->getJobResults());

        $names = array_map(fn($r) => $r->getJobName(), $result->getJobResults());
        $this->assertContains('job_a', $names);
        $this->assertContains('job_b', $names);
    }

    /** @test */
    public function parallel_three_jobs_with_two_slots()
    {
        $executor = new FlowExecutor(new NullOutputHandler());

        $jobs = [
            new CustomJob(new JobConfiguration('j1', 'custom', ['script' => 'echo 1'])),
            new CustomJob(new JobConfiguration('j2', 'custom', ['script' => 'echo 2'])),
            new CustomJob(new JobConfiguration('j3', 'custom', ['script' => 'echo 3'])),
        ];

        $plan = new FlowPlan('test', $jobs, new OptionsConfiguration(false, 2));
        $result = $executor->execute($plan);

        $this->assertTrue($result->isSuccess());
        $this->assertCount(3, $result->getJobResults());
    }

    /**
     * Regression: with processes=2 and a queued job declaring 4 uncontrollable
     * cores, the pre-3.3.1 allocator recorded coresByJob=4. FifoAdmission then
     * rejected the head forever (4 > 2 free) and FlowExecutor span at 100% CPU.
     * The clamp in ThreadBudgetAllocator caps the cost at the budget so the job
     * is admitted alone once the previous slots free.
     *
     * @test
     */
    public function parallel_does_not_deadlock_when_uncontrollable_default_exceeds_budget()
    {
        $executor = new FlowExecutor(new NullOutputHandler());

        $heavy = new class (new JobConfiguration('heavy', 'custom', ['script' => 'echo heavy'])) extends CustomJob {
            public function getThreadCapability(): ?ThreadCapability
            {
                return new ThreadCapability('_internal', 4, 1, false);
            }
        };

        $jobs = [
            new CustomJob(new JobConfiguration('light_a', 'custom', ['script' => 'echo a'])),
            new CustomJob(new JobConfiguration('light_b', 'custom', ['script' => 'echo b'])),
            $heavy,
        ];

        $plan = new FlowPlan('test', $jobs, new OptionsConfiguration(false, 2));

        // Belt-and-suspenders: if the deadlock returns this alarm fires
        // before PHPUnit's wall-clock budget. The previous bug span at 100% CPU
        // inside a no-usleep branch (running=[] && queue!=[]) so the signal
        // would only be processed if async dispatch is enabled.
        $async = function_exists('pcntl_async_signals') ? pcntl_async_signals(true) : false;
        if (function_exists('pcntl_alarm')) {
            pcntl_signal(SIGALRM, function () {
                $this->fail('FlowExecutor deadlocked: head job with cores > budget was never admitted');
            });
            pcntl_alarm(15);
        }

        try {
            $result = $executor->execute($plan);
        } finally {
            if (function_exists('pcntl_alarm')) {
                pcntl_alarm(0);
            }
            if (function_exists('pcntl_async_signals')) {
                pcntl_async_signals($async);
            }
        }

        $this->assertCount(3, $result->getJobResults());
        $names = array_map(fn($r) => $r->getJobName(), $result->getJobResults());
        $this->assertContains('heavy', $names);
        $this->assertTrue($result->isSuccess());
    }

    /**
     * Regression: a single-job flow normally goes to executeSequential, but
     * shouldSampleMemory() forces executeParallel when memory-budget, --stats
     * or per-job memory thresholds are declared. In that path,
     * fillSequentialAllocations recorded the capability's defaultThreads
     * verbatim — bypassing ThreadBudgetAllocator's clamp — so a phpstan-like
     * job (defaultThreads=4) with processes=2 and stats enabled produced
     * coresByJob=4 against a coresBudget=2, deadlocking FifoAdmission.
     * The clamp in buildProcessPool guarantees coresByJob ≤ coresBudget for
     * every code path that ends up in executeParallel.
     *
     * @test
     */
    public function parallel_single_job_with_stats_does_not_deadlock_when_default_threads_exceed_budget()
    {
        $executor = new FlowExecutor(new NullOutputHandler());

        $heavy = new class (new JobConfiguration('heavy', 'custom', ['script' => 'echo heavy'])) extends CustomJob {
            public function getThreadCapability(): ?ThreadCapability
            {
                return new ThreadCapability('_internal', 4, 1, false);
            }
        };

        // stats=true → shouldSampleMemory() returns true → executeParallel
        // even with a single job. processes=2 < default_threads=4.
        $options = new OptionsConfiguration(
            false,    // failFast
            2,        // processes (cores budget)
            null,     // mainBranch
            'full',   // fastBranchFallback
            '',       // executablePrefix
            [],       // reports
            null,     // timeBudget
            null,     // memoryBudget
            'fifo',   // allocator
            true      // stats
        );
        $plan = new FlowPlan('test', [$heavy], $options);

        $async = function_exists('pcntl_async_signals') ? pcntl_async_signals(true) : false;
        if (function_exists('pcntl_alarm')) {
            pcntl_signal(SIGALRM, function () {
                $this->fail('FlowExecutor deadlocked: single job with default_threads > budget was never admitted under stats=true');
            });
            pcntl_alarm(15);
        }

        try {
            $result = $executor->execute($plan);
        } finally {
            if (function_exists('pcntl_alarm')) {
                pcntl_alarm(0);
            }
            if (function_exists('pcntl_async_signals')) {
                pcntl_async_signals($async);
            }
        }

        $this->assertCount(1, $result->getJobResults());
        $this->assertTrue($result->getJobResults()[0]->isSuccess());
    }

    /**
     * Regression: BUG-002 — `--memory-warn-above=N` (or `memory-budget.warn-above`)
     * with N < max(jobs.memory) deadlocked the FlowExecutor. The bin-packing
     * reference (warn-above preferred, fail-above otherwise — see
     * MemoryBudgetConfiguration::getBinPackingReference) was used as a hard
     * admission ceiling, so a single job declaring `memory: 300` against a
     * warn-above of 200 never satisfied AdmissionContext::fits() and
     * FifoAdmission span at 100% CPU forever (the executeParallel loop only
     * sleeps when `hasRunning()` — with running=[] and queue!=[] it busy-waits).
     *
     * The fix mirrors the cores clamp in buildProcessPool: memory reservations
     * are clamped to memoryBudgetMb so a single oversized job is admitted alone
     * (consuming the full budget) and finishes the flow.
     *
     * Decision table — warn-above as bin-packing reference, processes=2:
     *   C  head_below_budget       head=100              budget=200  → admit
     *   D  head_at_boundary        head=200              budget=200  → admit
     *   E  head_above_budget       head=300              budget=200  → BUG: deadlock
     *   F  pair_fits_combined      head=100, tail=50     budget=200  → admit both
     *   H  head_above_with_tail    head=300, tail=50     budget=200  → BUG: deadlock
     *
     * @test
     * @dataProvider memoryAdmissionScenarios
     *
     * @param int[] $memoryReserves
     */
    public function parallel_does_not_deadlock_when_memory_reserve_exceeds_warn_above(
        string $caseLabel,
        int $warnAbove,
        array $memoryReserves
    ): void {
        $jobs = [];
        foreach ($memoryReserves as $idx => $memMb) {
            $jobs[] = new CustomJob(new JobConfiguration(
                'job_' . $idx,
                'custom',
                ['script' => 'echo j' . $idx, 'memory' => $memMb]
            ));
        }

        $options = new OptionsConfiguration(
            false,                                                                    // failFast
            2,                                                                        // processes
            null,                                                                     // mainBranch
            'full',                                                                   // fastBranchFallback
            '',                                                                       // executablePrefix
            [],                                                                       // reports
            null,                                                                     // timeBudget
            new \Wtyd\GitHooks\Configuration\MemoryBudgetConfiguration($warnAbove, null), // memoryBudget
            'fifo',                                                                   // allocator
            false                                                                     // stats
        );
        $plan = new FlowPlan('test', $jobs, $options);

        $executor = new FlowExecutor(new NullOutputHandler());

        // Belt-and-suspenders: when running=[] && queue!=[], the executeParallel
        // loop spins at 100% CPU without usleep, so PHPUnit's wall-clock budget
        // is the only thing that catches the deadlock. SIGALRM kills the spin
        // sooner with a clear failure message.
        $async = function_exists('pcntl_async_signals') ? pcntl_async_signals(true) : false;
        if (function_exists('pcntl_alarm')) {
            pcntl_signal(SIGALRM, function () use ($caseLabel) {
                $this->fail(
                    "FlowExecutor deadlocked on case '{$caseLabel}': "
                    . 'warn-above < max(jobs.memory) was treated as a hard admission ceiling'
                );
            });
            pcntl_alarm(15);
        }

        try {
            $result = $executor->execute($plan);
        } finally {
            if (function_exists('pcntl_alarm')) {
                pcntl_alarm(0);
            }
            if (function_exists('pcntl_async_signals')) {
                pcntl_async_signals($async);
            }
        }

        $this->assertCount(count($memoryReserves), $result->getJobResults());
        foreach ($result->getJobResults() as $jr) {
            $this->assertTrue(
                $jr->isSuccess(),
                sprintf('Job %s should have succeeded on case %s', $jr->getJobName(), $caseLabel)
            );
        }
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: int[]}>
     */
    public function memoryAdmissionScenarios(): array
    {
        return [
            'C: head below budget'           => ['C', 200, [100]],
            'D: head at boundary'            => ['D', 200, [200]],
            'E: head above budget (BUG-002)' => ['E', 200, [300]],
            'F: pair fits combined'          => ['F', 200, [100, 50]],
            'H: head above with tail (BUG)'  => ['H', 200, [300, 50]],
        ];
    }

    // ========================================================================
    // Peak threads tracking
    // ========================================================================

    /** @test */
    public function peak_estimated_threads_is_tracked()
    {
        $executor = new FlowExecutor(new NullOutputHandler());

        $jobs = [
            new CustomJob(new JobConfiguration('j1', 'custom', ['script' => 'echo 1'])),
            new CustomJob(new JobConfiguration('j2', 'custom', ['script' => 'echo 2'])),
        ];

        $plan = new FlowPlan('test', $jobs, new OptionsConfiguration(false, 1));
        $result = $executor->execute($plan);

        // Sequential: each job uses 1 thread, peak should be 1
        $this->assertGreaterThanOrEqual(1, $result->getPeakEstimatedThreads());
    }

    // ========================================================================
    // Thread budget
    // ========================================================================

    /** @test */
    public function thread_budget_is_propagated_to_flow_result()
    {
        $executor = new FlowExecutor(new NullOutputHandler());

        $job = new CustomJob(new JobConfiguration('j1', 'custom', ['script' => 'echo 1']));
        $options = new OptionsConfiguration(false, 4);
        $plan = new FlowPlan('test', [$job], $options);
        $result = $executor->execute($plan);

        $this->assertSame(4, $result->getThreadBudget());
    }

    // ========================================================================
    // Output handler callbacks
    // ========================================================================

    /** @test */
    public function success_job_triggers_onJobSuccess()
    {
        $handler = $this->createMock(OutputHandler::class);
        $handler->expects($this->once())->method('onJobSuccess');
        $handler->expects($this->never())->method('onJobError');

        $executor = new FlowExecutor($handler);

        $job = new CustomJob(new JobConfiguration('ok', 'custom', ['script' => 'echo ok']));
        $plan = new FlowPlan('test', [$job], new OptionsConfiguration(false, 1));
        $executor->execute($plan);
    }

    /** @test */
    public function failed_job_triggers_onJobError()
    {
        $handler = $this->createMock(OutputHandler::class);
        $handler->expects($this->once())->method('onJobError');
        $handler->expects($this->never())->method('onJobSuccess');

        $executor = new FlowExecutor($handler);

        $job = new CustomJob(new JobConfiguration('fail', 'custom', ['script' => 'echo error && exit 1']));
        $plan = new FlowPlan('test', [$job], new OptionsConfiguration(false, 1));
        $executor->execute($plan);
    }

    /** @test */
    public function flush_is_called_after_execution()
    {
        $handler = $this->createMock(OutputHandler::class);
        $handler->expects($this->once())->method('flush');

        $executor = new FlowExecutor($handler);

        $job = new CustomJob(new JobConfiguration('ok', 'custom', ['script' => 'echo ok']));
        $plan = new FlowPlan('test', [$job], new OptionsConfiguration(false, 1));
        $executor->execute($plan);
    }

    /**
     * @test
     * Regression: onFlowStart must receive the total count that will be observed
     * (executable jobs + plan-level skipped jobs such as fast-mode no-staged-files).
     * If the total excludes plan-skipped jobs, the ProgressOutputHandler emits
     * counters that overrun the denominator, e.g. [2/1], "Done. 2/1 completed.".
     */
    public function onFlowStart_total_includes_plan_skipped_jobs()
    {
        $spy = new OutputHandlerSpy();
        $executor = new FlowExecutor($spy);

        $activeJob = new CustomJob(new JobConfiguration('runs', 'custom', ['script' => 'echo ok']));
        $planSkipped = [
            'filtered_a' => ['type' => 'phpstan', 'reason' => 'no staged files match its paths', 'paths' => ['src']],
            'filtered_b' => ['type' => 'phpcs',   'reason' => 'no staged files match its paths', 'paths' => ['app']],
        ];

        $plan = new FlowPlan(
            'test',
            [$activeJob],
            new OptionsConfiguration(false, 1),
            null,
            $planSkipped
        );

        $executor->execute($plan);

        $this->assertSame([3], $spy->flowStarts, 'onFlowStart must announce total = active (1) + plan-skipped (2)');
        $this->assertCount(1, $spy->successfulJobs);
        $this->assertSame(['filtered_a', 'filtered_b'], $spy->skippedJobNames());
    }

    // ========================================================================
    // OutputHandlerSpy — event-stream assertions
    // ========================================================================

    /**
     * @test
     * Kills L258 MethodCallRemoval on `onJobStart` in the sequential path:
     * the spy must record both jobs as started in insertion order.
     */
    public function sequential_mode_fires_onJobStart_for_each_job_in_order()
    {
        $spy = new OutputHandlerSpy();
        $executor = new FlowExecutor($spy);

        $jobs = [
            new CustomJob(new JobConfiguration('job_a', 'custom', ['script' => 'echo a'])),
            new CustomJob(new JobConfiguration('job_b', 'custom', ['script' => 'echo b'])),
        ];
        $plan = new FlowPlan('test', $jobs, new OptionsConfiguration(false, 1));

        $executor->execute($plan);

        $this->assertSame(['job_a', 'job_b'], $spy->startedJobs);
        $this->assertSame([2], $spy->flowStarts);
        $this->assertSame(1, $spy->flushCount);
    }

    /**
     * @test
     * Kills L219 LogicalAnd→Or: `$failFast && !$result->isSuccess()` flipped
     * to `||` would trigger fail-fast after any successful completion. Two
     * passing jobs with failFast=true must never produce a skipped event.
     */
    public function parallel_fail_fast_does_not_trigger_on_successful_jobs()
    {
        $spy = new OutputHandlerSpy();
        $executor = new FlowExecutor($spy);

        $jobs = [
            new CustomJob(new JobConfiguration('job_a', 'custom', ['script' => 'echo a'])),
            new CustomJob(new JobConfiguration('job_b', 'custom', ['script' => 'echo b'])),
        ];
        $plan = new FlowPlan('test', $jobs, new OptionsConfiguration(true, 2));

        $result = $executor->execute($plan);

        $this->assertTrue($result->isSuccess());
        $this->assertSame([], $spy->skippedJobs);
        $this->assertCount(2, $spy->successfulJobs);
    }

    /**
     * @test
     * Kills L220 TrueValue: `$failFastTriggered = true` flipped to `false`
     * would let the parallel loop keep starting queued jobs. With 1 slot
     * and the first job failing, the second and third must appear only as
     * skipped events — never as started.
     */
    public function parallel_fail_fast_prevents_queued_jobs_from_starting()
    {
        $spy = new OutputHandlerSpy();
        $executor = new FlowExecutor($spy);

        $jobs = [
            new CustomJob(new JobConfiguration('fail_first', 'custom', ['script' => 'exit 1'])),
            new CustomJob(new JobConfiguration('queued_a', 'custom', ['script' => 'echo a'])),
            new CustomJob(new JobConfiguration('queued_b', 'custom', ['script' => 'echo b'])),
        ];
        $plan = new FlowPlan('test', $jobs, new OptionsConfiguration(true, 1));

        $executor->execute($plan);

        $this->assertSame(['fail_first'], $spy->startedJobs);
        $skipped = $spy->skippedJobNames();
        $this->assertContains('queued_a', $skipped);
        $this->assertContains('queued_b', $skipped);
    }

    /**
     * @test
     * Kills L224 MethodCallRemoval on `onJobSkipped` and L225 confirms the
     * reason string is propagated verbatim. Derived from the queued-jobs
     * scenario above but asserting the exact reason payload.
     */
    public function parallel_fail_fast_reports_skipped_jobs_with_exact_reason()
    {
        $spy = new OutputHandlerSpy();
        $executor = new FlowExecutor($spy);

        $jobs = [
            new CustomJob(new JobConfiguration('fail_first', 'custom', ['script' => 'exit 1'])),
            new CustomJob(new JobConfiguration('queued', 'custom', ['script' => 'echo a'])),
        ];
        $plan = new FlowPlan('test', $jobs, new OptionsConfiguration(true, 1));

        $executor->execute($plan);

        $this->assertNotEmpty($spy->skippedJobs);
        foreach ($spy->skippedJobs as $entry) {
            $this->assertSame('skipped by fail-fast', $entry['reason']);
        }
    }

    /**
     * @test
     * Kills L267 Identical `$type === Process::ERR`: a job that writes only
     * to stderr must produce an output entry with `isStderr === true`.
     * Flipping `===` to `!==` would mislabel every chunk.
     */
    public function stderr_output_is_routed_with_the_error_channel_flag()
    {
        $spy = new OutputHandlerSpy();
        $executor = new FlowExecutor($spy);

        $job = new CustomJob(new JobConfiguration('stderr_only', 'custom', [
            'script' => 'printf "boom" >&2; exit 1',
        ]));
        $plan = new FlowPlan('test', [$job], new OptionsConfiguration(false, 1));

        $executor->execute($plan);

        $this->assertContains('stderr_only', $spy->jobNamesWithStderrOutput());
        $stderrChunks = array_values(array_filter($spy->outputs, function (array $entry): bool {
            return $entry['isStderr'];
        }));
        $this->assertNotEmpty($stderrChunks);
        $this->assertStringContainsString('boom', implode('', array_column($stderrChunks, 'chunk')));
    }

    /**
     * @test
     * Kills L162 Coalesce `?? 1` in executeSequential: a swap would make the
     * peak equal to 1 regardless of the allocated capability. A single
     * PhpcsJob with parallel=8 must produce peakEstimatedThreads=8.
     */
    public function sequential_peak_threads_comes_from_allocated_capability_not_default()
    {
        $executor = new FlowExecutor(new NullOutputHandler());

        $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', [
            'paths'    => ['src'],
            'parallel' => 8,
        ]));
        // processes=8 keeps the declared parallel=8 below the flow budget
        // so the clamp does not apply — the test still pins the peak to
        // the allocated capability (vs. the default 1 a swap mutant would
        // produce).
        $plan = new FlowPlan('test', [$job], new OptionsConfiguration(false, 8));

        $result = $executor->execute($plan);

        $this->assertSame(8, $result->getPeakEstimatedThreads());
    }

    /**
     * @test
     * Kills L280 PlusEqual `+=`→`-=` and L278 DecrementInteger on
     * currentThreads=0→-1: two concurrent CustomJobs (no capability, default
     * 1 thread each) must push the peak to 2. The sleep ensures both jobs
     * overlap in the pool before either completes.
     *
     * @group slow
     */
    public function parallel_peak_threads_sums_concurrent_allocations()
    {
        $executor = new FlowExecutor(new NullOutputHandler());

        $jobs = [
            new CustomJob(new JobConfiguration('slow_a', 'custom', ['script' => 'sleep 0.2'])),
            new CustomJob(new JobConfiguration('slow_b', 'custom', ['script' => 'sleep 0.2'])),
        ];
        $plan = new FlowPlan('test', $jobs, new OptionsConfiguration(false, 2));

        $result = $executor->execute($plan);

        $this->assertSame(2, $result->getPeakEstimatedThreads());
    }

    /**
     * @test
     * Kills L299 Concat / ConcatOperandRemoval: the combined output assigned
     * to the JobResult must contain BOTH the stdout and stderr chunks. A
     * mutant that drops either operand would miss one stream entirely.
     */
    public function job_output_contains_both_stdout_and_stderr_concatenated()
    {
        $executor = new FlowExecutor(new NullOutputHandler());

        $job = new CustomJob(new JobConfiguration('mixed', 'custom', [
            'script' => 'printf "out-marker"; printf "err-marker" >&2',
        ]));
        $plan = new FlowPlan('test', [$job], new OptionsConfiguration(false, 1));

        $result = $executor->execute($plan);
        $output = $result->getJobResults()[0]->getOutput();

        $this->assertStringContainsString('out-marker', $output);
        $this->assertStringContainsString('err-marker', $output);
    }

    /**
     * @test
     * Kills L361 Multiplication `$seconds * 1000`→`/ 1000`: a 150ms sleep
     * must produce a triple-digit millisecond count, not 0ms. Parses the
     * rendered time literal to enforce a concrete lower bound.
     *
     * @group slow
     */
    public function execution_time_in_milliseconds_is_derived_from_multiplication()
    {
        $executor = new FlowExecutor(new NullOutputHandler());

        $job = new CustomJob(new JobConfiguration('slow', 'custom', ['script' => 'sleep 0.15']));
        $plan = new FlowPlan('test', [$job], new OptionsConfiguration(false, 1));

        $result = $executor->execute($plan);
        $time = $result->getJobResults()[0]->getExecutionTime();

        $this->assertMatchesRegularExpression('/^\d+ms$/', $time);
        $this->assertGreaterThan(100, (int) $time);
    }

    /**
     * @test
     * A job writing only to stdout must be routed with `isStderr === false`.
     * Pairs with the previous test to force identity on the channel flag.
     */
    public function stdout_output_is_routed_with_the_non_error_channel_flag()
    {
        $spy = new OutputHandlerSpy();
        $executor = new FlowExecutor($spy);

        $job = new CustomJob(new JobConfiguration('stdout_only', 'custom', [
            'script' => 'printf "hello"',
        ]));
        $plan = new FlowPlan('test', [$job], new OptionsConfiguration(false, 1));

        $executor->execute($plan);

        $this->assertNotContains('stdout_only', $spy->jobNamesWithStderrOutput());
        $this->assertNotEmpty(array_filter($spy->outputs, function (array $entry): bool {
            return !$entry['isStderr'];
        }));
    }

    // ========================================================================
    // cores: N override propagation
    // ========================================================================

    /** @test */
    public function cores_override_is_applied_to_controllable_job_in_dry_run()
    {
        $executor = new FlowExecutor(new NullOutputHandler());

        $phpcs = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', [
            'executable-path' => 'vendor/bin/phpcs',
            'paths'          => ['src'],
            'cores'          => 2,
        ]));
        // processes=8 keeps cores=2 below the budget — see
        // explicit_cores_override_clamps_args_to_flow_budget for the
        // clamp scenario.
        $plan = new FlowPlan('qa', [$phpcs], new OptionsConfiguration(false, 8));

        $result = $executor->execute($plan, true);

        $this->assertTrue($result->isSuccess());
        $commands = array_map(function ($jr) {
            return $jr->getCommand();
        }, $result->getJobResults());
        $this->assertStringContainsString('--parallel=2', $commands[0]);
    }

    /** @test */
    public function cores_override_is_applied_to_paratest_in_dry_run()
    {
        $executor = new FlowExecutor(new NullOutputHandler());

        $paratest = new ParatestJob(new JobConfiguration('paratest_all', 'paratest', [
            'executable-path' => 'vendor/bin/paratest',
            'configuration'  => 'phpunit.xml',
            'cores'          => 4,
        ]));
        // processes=8 keeps cores=4 below the budget so this test pins
        // the propagation to the native flag, not the clamp.
        $plan = new FlowPlan('qa', [$paratest], new OptionsConfiguration(false, 8));

        $result = $executor->execute($plan, true);

        $this->assertTrue($result->isSuccess());
        $commands = array_map(function ($jr) {
            return $jr->getCommand();
        }, $result->getJobResults());
        $this->assertStringContainsString('--processes=4', $commands[0]);
    }

    /**
     * @test
     * The flow rules: when `cores: N` exceeds the flow's processes budget,
     * the args propagated to the tool are clamped to the budget. Without
     * this clamp, the tool would actually spawn N workers while the pool
     * accounts for `min(N, budget)` — admitting other jobs that then
     * contend for cores the tool is silently consuming.
     */
    public function explicit_cores_override_clamps_args_to_flow_budget()
    {
        $executor = new FlowExecutor(new NullOutputHandler());

        $phpcs = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', [
            'executable-path' => 'vendor/bin/phpcs',
            'paths'           => ['src'],
            'cores'           => 8,
        ]));
        $plan = new FlowPlan('qa', [$phpcs], new OptionsConfiguration(false, 4));

        $result = $executor->execute($plan, true);

        $command = $result->getJobResults()[0]->getCommand();
        $this->assertStringContainsString('--parallel=4', $command);
        $this->assertStringNotContainsString('--parallel=8', $command);
    }

    /**
     * @test
     * Symmetric with the explicit-cores clamp: when only the native flag is
     * declared (and getCoresOverride() promotes it as implicit override),
     * the clamp must apply identically.
     */
    public function promoted_native_flag_clamps_args_to_flow_budget()
    {
        $executor = new FlowExecutor(new NullOutputHandler());

        $phpcs = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', [
            'executable-path' => 'vendor/bin/phpcs',
            'paths'           => ['src'],
            'parallel'        => 8,
        ]));
        $plan = new FlowPlan('qa', [$phpcs], new OptionsConfiguration(false, 4));

        $result = $executor->execute($plan, true);

        $command = $result->getJobResults()[0]->getCommand();
        $this->assertStringContainsString('--parallel=4', $command);
    }

    /**
     * @test
     * Negative pin: when the override fits within the budget, no clamp
     * is applied and the declared value reaches the tool verbatim.
     */
    public function explicit_cores_override_passes_through_when_below_budget()
    {
        $executor = new FlowExecutor(new NullOutputHandler());

        $phpcs = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', [
            'executable-path' => 'vendor/bin/phpcs',
            'paths'           => ['src'],
            'cores'           => 2,
        ]));
        $plan = new FlowPlan('qa', [$phpcs], new OptionsConfiguration(false, 8));

        $result = $executor->execute($plan, true);

        $command = $result->getJobResults()[0]->getCommand();
        $this->assertStringContainsString('--parallel=2', $command);
    }

    /**
     * @test
     * When sequential allocation runs (single job or processes=1), a
     * controllable capability default that exceeds the budget must also
     * be clamped — including the args propagated to the tool. Otherwise
     * parallel-lint's default `-j 10` would always win even on a 1-core
     * flow.
     *
     * ParallelLintJob default capability is `jobs: 10`. With processes=2
     * and no override declared, the tool must run with `-j 2`.
     */
    public function sequential_default_capability_clamps_args_to_flow_budget_for_controllable()
    {
        $executor = new FlowExecutor(new NullOutputHandler());

        $lint = new ParallelLintJob(new JobConfiguration('lint', 'parallel-lint', [
            'paths' => ['src'],
        ]));
        $plan = new FlowPlan('qa', [$lint], new OptionsConfiguration(false, 2));

        $result = $executor->execute($plan, true);

        $command = $result->getJobResults()[0]->getCommand();
        $this->assertStringContainsString('-j 2', $command);
        $this->assertStringNotContainsString('-j 10', $command);
    }

    /**
     * @test
     * Negative pin for the sequential clamp: uncontrollable capabilities
     * (phpstan reads `.neon`) have no CLI flag to clamp, so applyThreadLimit
     * must NOT be called and the command must remain unchanged. The
     * threadAllocations bookkeeping is still clamped so the pool's cores
     * accounting stays consistent.
     */
    public function sequential_default_capability_does_not_apply_thread_limit_for_uncontrollable()
    {
        $executor = new FlowExecutor(new NullOutputHandler());

        $phpstan = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', [
            'executable-path' => 'vendor/bin/phpstan',
            'paths'           => ['src'],
        ]));
        $plan = new FlowPlan('qa', [$phpstan], new OptionsConfiguration(false, 1));

        $result = $executor->execute($plan, true);

        $command = $result->getJobResults()[0]->getCommand();
        // phpstan has no CLI threads flag — the command is unaffected.
        $this->assertStringNotContainsString('--parallel', $command);
        $this->assertStringNotContainsString('--threads', $command);
    }

    /** @test */
    public function cores_override_wins_over_reparto_in_parallel_mode()
    {
        $executor = new FlowExecutor(new NullOutputHandler());

        // Without cores, the reparto would split the budget among phpcs and paratest.
        // With cores declared, each job gets its own fixed amount.
        $phpcs = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', [
            'executable-path' => 'vendor/bin/phpcs',
            'paths'          => ['src'],
            'cores'          => 2,
        ]));
        $paratest = new ParatestJob(new JobConfiguration('paratest_all', 'paratest', [
            'executable-path' => 'vendor/bin/paratest',
            'configuration'  => 'phpunit.xml',
            'cores'          => 4,
        ]));
        $plan = new FlowPlan('qa', [$phpcs, $paratest], new OptionsConfiguration(false, 8));

        $result = $executor->execute($plan, true);

        $jobResults = $result->getJobResults();
        $this->assertStringContainsString('--parallel=2', $jobResults[0]->getCommand());
        $this->assertStringContainsString('--processes=4', $jobResults[1]->getCommand());
    }

    // ========================================================================
    // Execution mode propagation (plan → result)
    // ========================================================================

    /** @test */
    public function execute_propagates_fast_mode_from_plan_to_result()
    {
        $executor = new FlowExecutor(new NullOutputHandler());

        $job = new CustomJob(new JobConfiguration('ok', 'custom', ['script' => 'echo ok']));
        $plan = new FlowPlan(
            'qa',
            [$job],
            new OptionsConfiguration(false, 1),
            null,
            [],
            \Wtyd\GitHooks\Execution\ExecutionMode::FAST
        );

        $result = $executor->execute($plan);

        $this->assertSame('fast', $result->getExecutionMode());
    }

    /** @test */
    public function execute_propagates_fast_branch_mode_from_plan_to_result()
    {
        $executor = new FlowExecutor(new NullOutputHandler());

        $job = new CustomJob(new JobConfiguration('ok', 'custom', ['script' => 'echo ok']));
        $plan = new FlowPlan(
            'qa',
            [$job],
            new OptionsConfiguration(false, 1),
            null,
            [],
            \Wtyd\GitHooks\Execution\ExecutionMode::FAST_BRANCH
        );

        $result = $executor->execute($plan);

        $this->assertSame('fast-branch', $result->getExecutionMode());
    }

    /** @test */
    public function execute_defaults_to_full_mode_when_plan_has_no_mode()
    {
        $executor = new FlowExecutor(new NullOutputHandler());

        $job = new CustomJob(new JobConfiguration('ok', 'custom', ['script' => 'echo ok']));
        $plan = new FlowPlan('qa', [$job], new OptionsConfiguration(false, 1));

        $result = $executor->execute($plan);

        $this->assertSame('full', $result->getExecutionMode());
    }

    /** @test */
    public function dry_run_propagates_execution_mode_from_plan_to_result()
    {
        $executor = new FlowExecutor(new NullOutputHandler());

        $job = new CustomJob(new JobConfiguration('ok', 'custom', ['script' => 'echo ok']));
        $plan = new FlowPlan(
            'qa',
            [$job],
            new OptionsConfiguration(),
            null,
            [],
            \Wtyd\GitHooks\Execution\ExecutionMode::FAST
        );

        $result = $executor->execute($plan, true);

        $this->assertSame('fast', $result->getExecutionMode());
    }

    // ========================================================================
    // Tier 2 mutation kills — coordinator contracts
    // ========================================================================

    /**
     * Kills FlowExecutor:530 MethodCallRemoval on `outputHandler->onJobSkipped`
     * in the PARALLEL fail-fast branch. The existing
     * `parallel_fail_fast_reports_skipped_jobs_with_exact_reason` runs with
     * processes=1 → falls into executeSequential and never touches line 530.
     * This case forces processes=2 with one in-flight slow job and one queued
     * job so the foreach over `getQueuedJobs()` is the only path that emits
     * the skipped event.
     *
     * @test
     */
    public function parallel_fail_fast_notifies_outputHandler_for_each_queued_job()
    {
        $spy = new OutputHandlerSpy();
        $executor = new FlowExecutor($spy);

        $jobs = [
            new CustomJob(new JobConfiguration('fail_first', 'custom', ['script' => 'exit 1'])),
            new CustomJob(new JobConfiguration('slow_inflight', 'custom', ['script' => 'sleep 5'])),
            new CustomJob(new JobConfiguration('queued', 'custom', ['script' => 'echo q'])),
        ];
        $plan = new FlowPlan('test', $jobs, new OptionsConfiguration(true, 2));

        $executor->execute($plan);

        $skippedNames = array_map(fn(array $entry) => $entry['job'], $spy->skippedJobs);
        $this->assertContains(
            'queued',
            $skippedNames,
            'The queued job must produce an onJobSkipped event in the parallel fail-fast loop'
        );
        foreach ($spy->skippedJobs as $entry) {
            if ($entry['job'] === 'queued') {
                $this->assertSame('skipped by fail-fast', $entry['reason']);
            }
        }
    }

    /**
     * Cross-component contract (CRUZADO): when a flow declares `memory-budget`
     * AND a job declares `memory:`, the FlowResult must surface
     * memoryBudgetState and the JobResult must surface memoryReserved.
     *
     * Kills:
     *  - FlowExecutor:489 MethodCallRemoval `$memoryHandler->setup($jobs)` —
     *    without setup, evaluator stays null, isActive() false, enrichSingle
     *    short-circuits before withMemoryReserved() and the FlowResult never
     *    gets memoryBudgetState set.
     *  - FlowExecutor:565 MethodCallRemoval final `$memoryHandler->tick(...)` —
     *    similar cross-cut: without the tick, getMemoryStats reports an empty
     *    snapshot.
     *
     * @test
     */
    public function flow_with_memory_budget_enriches_jobResult_with_memoryReserved_and_flow_state()
    {
        $job = new CustomJob(new JobConfiguration(
            'measured',
            'custom',
            ['script' => 'sleep 0.1', 'memory' => 100]
        ));

        $options = new OptionsConfiguration(
            false,                                                                    // failFast
            1,                                                                        // processes
            null,                                                                     // mainBranch
            'full',                                                                   // fastBranchFallback
            '',                                                                       // executablePrefix
            [],                                                                       // reports
            null,                                                                     // timeBudget
            new \Wtyd\GitHooks\Configuration\MemoryBudgetConfiguration(500, null),    // memoryBudget
            'fifo',                                                                   // allocator
            true                                                                      // stats — forces parallel + sampler
        );
        $plan = new FlowPlan('test', [$job], $options);

        $executor = new FlowExecutor(new NullOutputHandler());
        $result = $executor->execute($plan);

        $jobResult = $result->getJobResults()[0];
        $this->assertSame(
            100,
            $jobResult->getMemoryReserved(),
            'JobResult must carry the declared memoryReserved when memory-budget is active'
        );
        $this->assertNotNull(
            $result->getMemoryBudgetState(),
            'FlowResult must carry memoryBudgetState when memory-budget is configured'
        );
        $this->assertNotNull(
            $result->getMemoryStats(),
            'FlowResult must carry memoryStats when --stats is enabled'
        );
    }

    /**
     * Kills FlowExecutor:400 TrueValue (`$hasReservation = true → false`) and
     * FlowExecutor:422 LogicalAnd (`&&` → `||`) on the memory-clamp guard.
     *
     * Both mutations are observable through `peakEstimatedThreads`:
     *  - With $hasReservation forced to false, memoryBudgetMb stays null, the
     *    pool runs in 1D, and two `memory: 300` jobs run concurrently against
     *    a budget of 100 → peak=2 instead of 1.
     *  - With the clamp guard flipped to `||`, jobs whose `memory <= budget`
     *    get clamped TO budget anyway, consuming the whole budget alone —
     *    two `memory: 50` jobs against budget 100 then run sequentially
     *    (peak=1) instead of concurrently (peak=2).
     *
     * Decision table (processes=2, allocator=fifo, --stats=true):
     *   over_budget  : memory=[300,300], budget=100 → expected peak=1 (clamp serializes)
     *   under_budget : memory=[ 50, 50], budget=100 → expected peak=2 (no clamp, both fit)
     *
     * @test
     * @dataProvider memoryClampPeakScenarios
     *
     * @param int[] $memoryReserves
     */
    public function parallel_peak_threads_reflects_memory_budget_clamp(
        string $caseLabel,
        int $warnAbove,
        array $memoryReserves,
        int $expectedPeak
    ): void {
        $jobs = [];
        foreach ($memoryReserves as $idx => $memMb) {
            $jobs[] = new CustomJob(new JobConfiguration(
                'job_' . $idx,
                'custom',
                ['script' => 'sleep 0.2', 'memory' => $memMb]
            ));
        }

        $options = new OptionsConfiguration(
            false,
            2,
            null,
            'full',
            '',
            [],
            null,
            new \Wtyd\GitHooks\Configuration\MemoryBudgetConfiguration($warnAbove, null),
            'fifo',
            false
        );
        $plan = new FlowPlan('test', $jobs, $options);

        $executor = new FlowExecutor(new NullOutputHandler());
        $result = $executor->execute($plan);

        $this->assertSame(
            $expectedPeak,
            $result->getPeakEstimatedThreads(),
            sprintf('Case %s: expected peak=%d', $caseLabel, $expectedPeak)
        );
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: int[], 3: int}>
     */
    public function memoryClampPeakScenarios(): array
    {
        return [
            'over_budget — clamp serializes'  => ['over_budget',  100, [300, 300], 1],
            'under_budget — both fit, no clamp' => ['under_budget', 100, [50, 50],   2],
        ];
    }

    /**
     * Decision table for FlowExecutor::shouldSampleMemory(). The method is
     * private, so we observe its effect: when it returns true, FlowExecutor
     * forces executeParallel (instantiating FlowMemoryHandler) even with a
     * single job and processes=1; when false, it falls through to
     * executeSequential (memoryHandler stays null → no stats / no per-job
     * memory enrichment).
     *
     * Kills:
     *  - FlowExecutor:455 TrueValue (stats branch `return true → false`):
     *    when --stats is on, dropping the return makes the method fall through
     *    to the budget check (null here), then the per-job loop (null), then
     *    `return false` → sequential → memoryStats stays null on the result.
     *  - FlowExecutor:455 ReturnRemoval (same branch, missing return → null):
     *    null is falsy, identical observable effect.
     *  - FlowExecutor:458 TrueValue and ReturnRemoval (memory-budget branch):
     *    same shape — flipping the return drops the flow into sequential mode
     *    and memoryBudgetState never gets attached.
     *
     * Decision table (1 job, processes=1, fail-fast=false):
     *
     *   case          | stats | memBudget | jobMem | observable
     *   stats_only    | true  | null      | null   | flowResult.memoryStats != null
     *   budget_only   | false | (500,nil) | null   | flowResult.memoryBudgetState != null
     *   jobmem_only   | false | null      | 100    | jobResult.memoryReserved == 100
     *   none          | false | null      | null   | both null (sequential, no handler)
     *
     * @test
     * @dataProvider shouldSampleMemoryScenarios
     */
    public function shouldSampleMemory_observable_effect(
        string $caseLabel,
        bool $stats,
        ?\Wtyd\GitHooks\Configuration\MemoryBudgetConfiguration $memoryBudget,
        ?int $jobMemory,
        bool $expectMemoryStats,
        bool $expectMemoryBudgetState,
        ?int $expectJobMemoryReserved
    ): void {
        $args = ['script' => 'sleep 0.1'];
        if ($jobMemory !== null) {
            $args['memory'] = $jobMemory;
        }
        $job = new CustomJob(new JobConfiguration('only', 'custom', $args));

        $options = new OptionsConfiguration(
            false,
            1,
            null,
            'full',
            '',
            [],
            null,
            $memoryBudget,
            'fifo',
            $stats
        );
        $plan = new FlowPlan('test', [$job], $options);

        $executor = new FlowExecutor(new NullOutputHandler());
        $result = $executor->execute($plan);

        if ($expectMemoryStats) {
            $this->assertNotNull(
                $result->getMemoryStats(),
                "Case {$caseLabel}: shouldSampleMemory must force parallel and populate memoryStats"
            );
        } else {
            $this->assertNull(
                $result->getMemoryStats(),
                "Case {$caseLabel}: with no --stats / budget / job memory, memoryStats must stay null"
            );
        }

        if ($expectMemoryBudgetState) {
            $this->assertNotNull(
                $result->getMemoryBudgetState(),
                "Case {$caseLabel}: memoryBudgetState must be attached when memory-budget is configured"
            );
        } else {
            $this->assertNull(
                $result->getMemoryBudgetState(),
                "Case {$caseLabel}: memoryBudgetState must stay null without memory-budget"
            );
        }

        $jobResult = $result->getJobResults()[0];
        if ($expectJobMemoryReserved !== null) {
            $this->assertSame(
                $expectJobMemoryReserved,
                $jobResult->getMemoryReserved(),
                "Case {$caseLabel}: jobResult.memoryReserved must surface the declared `memory:` value"
            );
        } else {
            $this->assertNull(
                $jobResult->getMemoryReserved(),
                "Case {$caseLabel}: jobResult.memoryReserved must stay null without `memory:`"
            );
        }
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: ?\Wtyd\GitHooks\Configuration\MemoryBudgetConfiguration, 3: ?int, 4: bool, 5: bool, 6: ?int}>
     */
    public function shouldSampleMemoryScenarios(): array
    {
        return [
            'stats_only'    => ['stats_only',  true,  null,                                                                          null, true,  false, null],
            'budget_only'   => ['budget_only', false, new \Wtyd\GitHooks\Configuration\MemoryBudgetConfiguration(500, null), null, false, true,  null],
            'jobmem_only'   => ['jobmem_only', false, null,                                                                          100,  false, false, 100],
            'none'          => ['none',        false, null,                                                                          null, false, false, null],
        ];
    }

    /**
     * Cross-component contract (CRUZADO) — FlowExecutor:440 Ternary swap on
     * `resolveAdmissionStrategy()` (`GREEDY ? new GreedyAdmission() : new FifoAdmission()`
     * → operands swapped). The mutant pairs each allocator with the wrong
     * strategy, but the existing direct strategy tests (FifoAdmissionTest,
     * GreedyAdmissionTest) cannot detect it because they instantiate the
     * strategies directly — never going through FlowExecutor.
     *
     * The observable difference lives in admission order under memory pressure:
     * with a queue [BIG(80), MED(80), SMALL(20)] against memoryBudget=100 and
     * BIG already running, FifoAdmission only inspects the head (MED) and
     * blocks; GreedyAdmission scans the whole queue and admits SMALL alongside
     * BIG. That changes the COMPLETION ORDER, which is what we assert.
     *
     * Timing model (sleeps chosen so the in-flight phase is observable):
     *   FIFO   : t=0 admit BIG; head MED blocks; t=0.4 BIG done, MED admit;
     *            t=0.45 MED done, SMALL admit; t=0.5 SMALL done.
     *            Completion order → [BIG, MED, SMALL].
     *   GREEDY : t=0 admit BIG; MED blocked but SMALL fits → admit SMALL;
     *            t=0.05 SMALL done; t=0.4 BIG done, MED admit; t=0.45 MED done.
     *            Completion order → [SMALL, BIG, MED].
     *
     * @test
     * @dataProvider allocatorStrategyScenarios
     *
     * @param string $allocator             AllocatorStrategy literal: 'fifo' | 'greedy'.
     * @param string $expectedFirstFinished Job name expected at the head of getJobResults().
     *
     * @group slow
     */
    public function flow_executor_wires_allocator_to_admission_strategy(
        string $allocator,
        string $expectedFirstFinished
    ): void {
        $jobs = [
            new CustomJob(new JobConfiguration('big', 'custom', ['script' => 'sleep 0.4', 'memory' => 80])),
            new CustomJob(new JobConfiguration('med', 'custom', ['script' => 'sleep 0.05', 'memory' => 80])),
            new CustomJob(new JobConfiguration('small', 'custom', ['script' => 'sleep 0.05', 'memory' => 20])),
        ];

        $options = new OptionsConfiguration(
            false,                                                                    // failFast
            2,                                                                        // processes (slot count)
            null,
            'full',
            '',
            [],
            null,
            new \Wtyd\GitHooks\Configuration\MemoryBudgetConfiguration(100, null),    // budget tight enough to bottleneck
            $allocator,                                                               // fifo | greedy
            false
        );
        $plan = new FlowPlan('test', $jobs, $options);

        $executor = new FlowExecutor(new NullOutputHandler());
        $result = $executor->execute($plan);

        $names = array_map(fn($r) => $r->getJobName(), $result->getJobResults());
        $this->assertSame(
            $expectedFirstFinished,
            $names[0],
            sprintf(
                'Allocator %s: first job to finish must be %s (got [%s]). FlowExecutor:440 Ternary swap '
                    . 'pairs the allocator label with the wrong AdmissionStrategy, flipping the order.',
                $allocator,
                $expectedFirstFinished,
                implode(', ', $names)
            )
        );
    }

    /**
     * Data provider for flow_executor_wires_allocator_to_admission_strategy.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public function allocatorStrategyScenarios(): array
    {
        return [
            'fifo blocks behind big head'   => ['fifo',   'big'],
            'greedy skips to small'         => ['greedy', 'small'],
        ];
    }
}
