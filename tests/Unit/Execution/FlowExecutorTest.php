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
use Wtyd\GitHooks\Jobs\CustomJob;
use Wtyd\GitHooks\Jobs\ParatestJob;
use Wtyd\GitHooks\Jobs\PhpcsJob;
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
        }
    }

    /** @test */
    public function dry_run_calls_onJobDryRun_for_each_job()
    {
        $handler = $this->createMock(OutputHandler::class);
        $handler->expects($this->exactly(2))
            ->method('onJobDryRun');
        $handler->expects($this->once())->method('flush');

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
        // 'never' should not be in results (skipped, not executed)
        $this->assertNotContains('never', $names);
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
            'ignoreErrorsOnExit' => true,
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
        $plan = new FlowPlan('test', [$job], new OptionsConfiguration(false, 1));

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
            'executablePath' => 'vendor/bin/phpcs',
            'paths'          => ['src'],
            'cores'          => 2,
        ]));
        $plan = new FlowPlan('qa', [$phpcs], new OptionsConfiguration(false, 1));

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
            'executablePath' => 'vendor/bin/paratest',
            'configuration'  => 'phpunit.xml',
            'cores'          => 4,
        ]));
        $plan = new FlowPlan('qa', [$paratest], new OptionsConfiguration(false, 1));

        $result = $executor->execute($plan, true);

        $this->assertTrue($result->isSuccess());
        $commands = array_map(function ($jr) {
            return $jr->getCommand();
        }, $result->getJobResults());
        $this->assertStringContainsString('--processes=4', $commands[0]);
    }

    /** @test */
    public function cores_override_wins_over_reparto_in_parallel_mode()
    {
        $executor = new FlowExecutor(new NullOutputHandler());

        // Without cores, the reparto would split the budget among phpcs and paratest.
        // With cores declared, each job gets its own fixed amount.
        $phpcs = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', [
            'executablePath' => 'vendor/bin/phpcs',
            'paths'          => ['src'],
            'cores'          => 2,
        ]));
        $paratest = new ParatestJob(new JobConfiguration('paratest_all', 'paratest', [
            'executablePath' => 'vendor/bin/paratest',
            'configuration'  => 'phpunit.xml',
            'cores'          => 4,
        ]));
        $plan = new FlowPlan('qa', [$phpcs, $paratest], new OptionsConfiguration(false, 8));

        $result = $executor->execute($plan, true);

        $jobResults = $result->getJobResults();
        $this->assertStringContainsString('--parallel=2', $jobResults[0]->getCommand());
        $this->assertStringContainsString('--processes=4', $jobResults[1]->getCommand());
    }
}
