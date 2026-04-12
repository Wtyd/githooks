<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use Tests\Doubles\GitStagerFake;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowPlan;
use Wtyd\GitHooks\Jobs\CustomJob;
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
}
