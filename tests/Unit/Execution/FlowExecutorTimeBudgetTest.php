<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Configuration\TimeBudgetConfiguration;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowPlan;
use Wtyd\GitHooks\Execution\JobResult;
use Wtyd\GitHooks\Jobs\CustomJob;
use Wtyd\GitHooks\Output\NullOutputHandler;

/**
 * Tests covering v3.3 item 4: per-job thresholds and flow time-budget.
 *
 * Uses CustomJob with `sleep` scripts to drive real elapsed time. Each test
 * runs in 0–2s so the suite stays under its current budget.
 */
class FlowExecutorTimeBudgetTest extends TestCase
{
    // ========================================================================
    // Per-job thresholds
    // ========================================================================

    /** @test */
    public function job_without_threshold_reports_no_state(): void
    {
        $job = new CustomJob(new JobConfiguration('a', 'custom', ['script' => 'true']));
        $plan = new FlowPlan('test', [$job], new OptionsConfiguration());

        $result = (new FlowExecutor(new NullOutputHandler()))->execute($plan);
        $jobResult = $result->getJobResults()[0];

        $this->assertSame(JobResult::THRESHOLD_NONE, $jobResult->getThresholdState());
        $this->assertFalse($jobResult->hasThreshold());
        $this->assertGreaterThan(0.0, $jobResult->getDurationSeconds());
    }

    /** @test */
    public function job_marks_warned_when_warn_after_crossed(): void
    {
        $job = new CustomJob(new JobConfiguration('slow', 'custom', [
            'script'     => 'sleep 1',
            'warn-after' => 1,
            'fail-after' => 5,
        ]));
        $plan = new FlowPlan('test', [$job], new OptionsConfiguration());

        $result = (new FlowExecutor(new NullOutputHandler()))->execute($plan);
        $jobResult = $result->getJobResults()[0];

        $this->assertSame(JobResult::THRESHOLD_WARNED, $jobResult->getThresholdState());
        $this->assertSame(JobResult::THRESHOLD_REASON_WARN, $jobResult->getThresholdReason());
        $this->assertTrue($jobResult->isSuccess());
        $this->assertSame(1, $jobResult->getConfiguredWarnAfter());
        $this->assertSame(5, $jobResult->getConfiguredFailAfter());
    }

    /** @test */
    public function job_marks_failed_and_flips_to_ko_when_fail_after_crossed(): void
    {
        $job = new CustomJob(new JobConfiguration('slow', 'custom', [
            'script'     => 'sleep 1',
            'fail-after' => 1,
        ]));
        $plan = new FlowPlan('test', [$job], new OptionsConfiguration());

        $result = (new FlowExecutor(new NullOutputHandler()))->execute($plan);
        $jobResult = $result->getJobResults()[0];

        $this->assertSame(JobResult::THRESHOLD_FAILED, $jobResult->getThresholdState());
        $this->assertFalse($jobResult->isSuccess());
        $this->assertFalse($result->isSuccess());
    }

    /** @test */
    public function real_ko_keeps_primary_cause_when_threshold_also_crosses(): void
    {
        $job = new CustomJob(new JobConfiguration('failing', 'custom', [
            'script'     => 'sleep 1 && false',
            'fail-after' => 1,
        ]));
        $plan = new FlowPlan('test', [$job], new OptionsConfiguration());

        $result = (new FlowExecutor(new NullOutputHandler()))->execute($plan);
        $jobResult = $result->getJobResults()[0];

        // Real KO: tool exited non-zero. Threshold annotated but NOT the cause.
        $this->assertFalse($jobResult->isSuccess());
        $this->assertNotSame(0, $jobResult->getExitCode());
        $this->assertSame(JobResult::THRESHOLD_FAILED, $jobResult->getThresholdState());
    }

    /** @test */
    public function thresholds_disabled_short_circuits_evaluation(): void
    {
        $executor = new FlowExecutor(new NullOutputHandler());
        $executor->setThresholdsDisabled(true);

        $job = new CustomJob(new JobConfiguration('slow', 'custom', [
            'script'     => 'sleep 1',
            'fail-after' => 1,
        ]));
        $plan = new FlowPlan('test', [$job], new OptionsConfiguration());

        $result = $executor->execute($plan);
        $jobResult = $result->getJobResults()[0];

        $this->assertSame(JobResult::THRESHOLD_NONE, $jobResult->getThresholdState());
        $this->assertTrue($jobResult->isSuccess());
        $this->assertNull($result->getTimeBudgetState());
    }

    // ========================================================================
    // Flow time-budget
    // ========================================================================

    /** @test */
    public function flow_time_budget_state_is_null_when_not_configured(): void
    {
        $job = new CustomJob(new JobConfiguration('a', 'custom', ['script' => 'true']));
        $plan = new FlowPlan('test', [$job], new OptionsConfiguration());

        $result = (new FlowExecutor(new NullOutputHandler()))->execute($plan);

        $this->assertNull($result->getTimeBudgetState());
    }

    /** @test */
    public function flow_fails_when_time_budget_exceeded_even_if_all_jobs_pass(): void
    {
        $options = new OptionsConfiguration(
            false,
            1,
            null,
            'full',
            '',
            [],
            new TimeBudgetConfiguration(null, 1)
        );

        $job = new CustomJob(new JobConfiguration('slow', 'custom', ['script' => 'sleep 1']));
        $plan = new FlowPlan('test', [$job], $options);

        $result = (new FlowExecutor(new NullOutputHandler()))->execute($plan);

        $this->assertNotNull($result->getTimeBudgetState());
        $this->assertTrue($result->getTimeBudgetState()->isFailed());
        $this->assertFalse($result->isSuccess(), 'Flow exit should be 1 even if jobs passed');
        $this->assertGreaterThanOrEqual(1.0, $result->getTimeBudgetState()->getTotalJobDuration());
    }

    /** @test */
    public function flow_warns_when_time_budget_warn_after_crossed_only(): void
    {
        $options = new OptionsConfiguration(
            false,
            1,
            null,
            'full',
            '',
            [],
            new TimeBudgetConfiguration(1, null)
        );

        $job = new CustomJob(new JobConfiguration('slow', 'custom', ['script' => 'sleep 1']));
        $plan = new FlowPlan('test', [$job], $options);

        $result = (new FlowExecutor(new NullOutputHandler()))->execute($plan);

        $this->assertTrue($result->getTimeBudgetState()->isWarned());
        $this->assertFalse($result->getTimeBudgetState()->isFailed());
        $this->assertTrue($result->isSuccess());
    }

    /** @test */
    public function skipped_jobs_do_not_contribute_to_time_budget_sum(): void
    {
        $options = new OptionsConfiguration(
            false,
            1,
            null,
            'full',
            '',
            [],
            new TimeBudgetConfiguration(null, 5)
        );

        $job = new CustomJob(new JobConfiguration('a', 'custom', ['script' => 'true']));
        $plan = new FlowPlan(
            'test',
            [$job],
            $options,
            null,
            ['skipped_job' => ['type' => 'phpcs', 'reason' => 'no staged files', 'paths' => []]]
        );

        $result = (new FlowExecutor(new NullOutputHandler()))->execute($plan);

        $this->assertNotNull($result->getTimeBudgetState());
        // Only the executed job duration contributes; skipped jobs are excluded.
        $this->assertLessThan(1.0, $result->getTimeBudgetState()->getTotalJobDuration());
    }
}
