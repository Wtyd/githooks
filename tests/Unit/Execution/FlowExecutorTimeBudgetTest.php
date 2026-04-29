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
 * Drives elapsed time through a closure-clock injected into FlowExecutor
 * via the protected setClock() seam. Scripts use `'true'` (or `'false'`
 * for failing jobs) so subprocesses run instantly; the perceived duration
 * comes from the canned tick sequence.
 *
 * The end-to-end path (real Symfony Process + real microtime) is covered
 * by FlowExecutorTimeBudgetIntegrationTest under @group integration.
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
            'script'     => 'true',
            'warn-after' => 1,
            'fail-after' => 5,
        ]));
        $plan = new FlowPlan('test', [$job], new OptionsConfiguration());

        $executor = $this->executorWithClock([1000.0, 1000.0, 1001.5, 1001.5]);
        $result = $executor->execute($plan);
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
            'script'     => 'true',
            'fail-after' => 1,
        ]));
        $plan = new FlowPlan('test', [$job], new OptionsConfiguration());

        $executor = $this->executorWithClock([1000.0, 1000.0, 1001.5, 1001.5]);
        $result = $executor->execute($plan);
        $jobResult = $result->getJobResults()[0];

        $this->assertSame(JobResult::THRESHOLD_FAILED, $jobResult->getThresholdState());
        $this->assertFalse($jobResult->isSuccess());
        $this->assertFalse($result->isSuccess());
    }

    /** @test */
    public function real_ko_keeps_primary_cause_when_threshold_also_crosses(): void
    {
        $job = new CustomJob(new JobConfiguration('failing', 'custom', [
            'script'     => 'false',
            'fail-after' => 1,
        ]));
        $plan = new FlowPlan('test', [$job], new OptionsConfiguration());

        $executor = $this->executorWithClock([1000.0, 1000.0, 1001.5, 1001.5]);
        $result = $executor->execute($plan);
        $jobResult = $result->getJobResults()[0];

        // Real KO: tool exited non-zero. Threshold annotated but NOT the cause.
        $this->assertFalse($jobResult->isSuccess());
        $this->assertNotSame(0, $jobResult->getExitCode());
        $this->assertSame(JobResult::THRESHOLD_FAILED, $jobResult->getThresholdState());
    }

    /** @test */
    public function thresholds_disabled_short_circuits_evaluation(): void
    {
        $executor = $this->executorWithClock([1000.0, 1000.0, 1001.5, 1001.5]);
        $executor->setThresholdsDisabled(true);

        $job = new CustomJob(new JobConfiguration('slow', 'custom', [
            'script'     => 'true',
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

        $job = new CustomJob(new JobConfiguration('slow', 'custom', ['script' => 'true']));
        $plan = new FlowPlan('test', [$job], $options);

        $executor = $this->executorWithClock([1000.0, 1000.0, 1001.5, 1001.5]);
        $result = $executor->execute($plan);

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

        $job = new CustomJob(new JobConfiguration('slow', 'custom', ['script' => 'true']));
        $plan = new FlowPlan('test', [$job], $options);

        $executor = $this->executorWithClock([1000.0, 1000.0, 1001.5, 1001.5]);
        $result = $executor->execute($plan);

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

    // ========================================================================
    // Clock seam regression test
    // ========================================================================

    /**
     * @test
     * Guards against a future commit accidentally re-introducing
     * microtime(true) inside FlowExecutor's sequential path. With a
     * stuck-at-zero clock, the only way to observe a non-zero elapsed
     * is for production to bypass now() — that breaks the assertion.
     */
    public function flow_executor_uses_only_the_injected_clock(): void
    {
        $job = new CustomJob(new JobConfiguration('a', 'custom', ['script' => 'true']));
        $plan = new FlowPlan('test', [$job], new OptionsConfiguration());

        // Stuck clock: every now() call returns 0.0.
        $executor = $this->executorWithClock([0.0, 0.0, 0.0, 0.0]);
        $result = $executor->execute($plan);

        $this->assertSame(0.0, $result->getJobResults()[0]->getDurationSeconds());
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    /**
     * Build a FlowExecutor whose now() returns each tick in order. The
     * closure throws LogicException when the array is exhausted, so any
     * unexpected extra now() call surfaces as a loud test failure.
     *
     * @param float[] $ticks
     */
    private function executorWithClock(array $ticks): FlowExecutor
    {
        $idx = 0;
        $executor = new class (new NullOutputHandler()) extends FlowExecutor {
            public function publicSetClock(callable $clock): void
            {
                $this->setClock($clock);
            }
        };
        $executor->publicSetClock(function () use (&$ticks, &$idx) {
            if (!isset($ticks[$idx])) {
                throw new \LogicException("Clock exhausted at call #{$idx} (provided " . count($ticks) . ' ticks)');
            }
            return $ticks[$idx++];
        });
        return $executor;
    }
}
