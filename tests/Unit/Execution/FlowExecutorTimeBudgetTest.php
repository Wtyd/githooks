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
    // Per-job thresholds

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

    /** @test */
    public function job_marks_warned_when_elapsed_exactly_equals_warn_after(): void
    {
        // Kills GreaterThanOrEqualTo `>=` -> `>` on `$elapsed >= $warnAfter`:
        // mutant would NOT fire at exact equality.
        $job = new CustomJob(new JobConfiguration('boundary', 'custom', [
            'script'     => 'true',
            'warn-after' => 1,
            'fail-after' => 5,
        ]));
        $plan = new FlowPlan('test', [$job], new OptionsConfiguration());

        $executor = $this->executorWithClock([1000.0, 1000.0, 1001.0, 1001.0]);
        $result = $executor->execute($plan);
        $jobResult = $result->getJobResults()[0];

        $this->assertSame(JobResult::THRESHOLD_WARNED, $jobResult->getThresholdState());
        $this->assertSame(1.0, $jobResult->getDurationSeconds());
    }

    /** @test */
    public function job_marks_failed_when_elapsed_exactly_equals_fail_after(): void
    {
        // Kills GreaterThanOrEqualTo `>=` -> `>` on `$elapsed >= $failAfter`.
        $job = new CustomJob(new JobConfiguration('boundary', 'custom', [
            'script'     => 'true',
            'fail-after' => 2,
        ]));
        $plan = new FlowPlan('test', [$job], new OptionsConfiguration());

        $executor = $this->executorWithClock([1000.0, 1000.0, 1002.0, 1002.0]);
        $result = $executor->execute($plan);
        $jobResult = $result->getJobResults()[0];

        $this->assertSame(JobResult::THRESHOLD_FAILED, $jobResult->getThresholdState());
    }

    /** @test */
    public function job_reports_no_threshold_state_when_only_warn_after_set_and_below(): void
    {
        // Kills Identical `=== null && === null` -> `!== null && !== null`
        // on the early-return guard at line 690: with `!==`, the guard
        // would never fire and a non-null configured warnAfter would
        // proceed to the comparison even when elapsed is below threshold.
        $job = new CustomJob(new JobConfiguration('below', 'custom', [
            'script'     => 'true',
            'warn-after' => 5,
        ]));
        $plan = new FlowPlan('test', [$job], new OptionsConfiguration());

        $executor = $this->executorWithClock([1000.0, 1000.0, 1000.5, 1000.5]);
        $result = $executor->execute($plan);
        $jobResult = $result->getJobResults()[0];

        $this->assertSame(JobResult::THRESHOLD_NONE, $jobResult->getThresholdState());
        $this->assertSame(5, $jobResult->getConfiguredWarnAfter());
    }

    /** @test */
    public function flow_marks_failed_when_sum_exactly_equals_fail_after(): void
    {
        // Kills GreaterThanOrEqualTo `>=` -> `>` on `$sum >= $failAfter`
        // (line 728): mutant would NOT fire at exact equality.
        $options = new OptionsConfiguration(
            false,
            1,
            null,
            'full',
            '',
            [],
            new TimeBudgetConfiguration(null, 2)
        );

        $job = new CustomJob(new JobConfiguration('two', 'custom', ['script' => 'true']));
        $plan = new FlowPlan('test', [$job], $options);

        $executor = $this->executorWithClock([1000.0, 1000.0, 1002.0, 1002.0]);
        $result = $executor->execute($plan);

        $this->assertNotNull($result->getTimeBudgetState());
        $this->assertTrue($result->getTimeBudgetState()->isFailed());
        $this->assertSame(2.0, $result->getTimeBudgetState()->getTotalJobDuration());
    }

    /** @test */
    public function flow_marks_warned_when_sum_exactly_equals_warn_after(): void
    {
        // Kills GreaterThanOrEqualTo `>=` -> `>` on `$sum >= $warnAfter`
        // (line 729) AND LogicalAnd `&&` -> `||` between the !$failed
        // and the warnAfter !== null guards.
        $options = new OptionsConfiguration(
            false,
            1,
            null,
            'full',
            '',
            [],
            new TimeBudgetConfiguration(2, null)
        );

        $job = new CustomJob(new JobConfiguration('two', 'custom', ['script' => 'true']));
        $plan = new FlowPlan('test', [$job], $options);

        $executor = $this->executorWithClock([1000.0, 1000.0, 1002.0, 1002.0]);
        $result = $executor->execute($plan);

        $this->assertTrue($result->getTimeBudgetState()->isWarned());
        $this->assertFalse($result->getTimeBudgetState()->isFailed());
    }

    /** @test */
    public function flow_remains_below_threshold_when_sum_strictly_under_warn_after(): void
    {
        // Pairs with the equal-boundary test: at sum=1.999 (just under
        // the 2.0 threshold), neither warned nor failed must fire.
        $options = new OptionsConfiguration(
            false,
            1,
            null,
            'full',
            '',
            [],
            new TimeBudgetConfiguration(2, 5)
        );

        $job = new CustomJob(new JobConfiguration('almost', 'custom', ['script' => 'true']));
        $plan = new FlowPlan('test', [$job], $options);

        $executor = $this->executorWithClock([1000.0, 1000.0, 1001.999, 1001.999]);
        $result = $executor->execute($plan);

        $this->assertFalse($result->getTimeBudgetState()->isWarned());
        $this->assertFalse($result->getTimeBudgetState()->isFailed());
    }

    /** @test */
    public function execution_time_under_one_second_is_rendered_as_milliseconds(): void
    {
        // Pins the ms branch (`$seconds < 1`): elapsed=0.5 -> "500ms".
        $job = new CustomJob(new JobConfiguration('half', 'custom', ['script' => 'true']));
        $plan = new FlowPlan('test', [$job], new OptionsConfiguration());

        $executor = $this->executorWithClock([1000.0, 1000.0, 1000.5, 1000.5]);
        $result = $executor->execute($plan);

        $this->assertSame('500ms', $result->getJobResults()[0]->getExecutionTime());
    }

    /** @test */
    public function execution_time_between_one_and_sixty_seconds_uses_two_decimal_seconds(): void
    {
        // Kills DecrementInteger / IncrementInteger / Concat / Concat
        // OperandRemoval / ReturnRemoval mutants on line 785
        // (`number_format($seconds, 2) . 's'`): elapsed=1.5 -> exactly "1.50s".
        $job = new CustomJob(new JobConfiguration('mid', 'custom', ['script' => 'true']));
        $plan = new FlowPlan('test', [$job], new OptionsConfiguration());

        $executor = $this->executorWithClock([1000.0, 1000.0, 1001.5, 1001.5]);
        $result = $executor->execute($plan);

        $this->assertSame('1.50s', $result->getJobResults()[0]->getExecutionTime());
    }

    /** @test */
    public function execution_time_at_exactly_sixty_seconds_falls_into_minutes_branch(): void
    {
        // Kills LessThan `<` -> `<=` on `$seconds < 60` (line 784):
        // mutant would render 60s as "60.00s"; original renders "1m 0s".
        $job = new CustomJob(new JobConfiguration('one_min', 'custom', ['script' => 'true']));
        $plan = new FlowPlan('test', [$job], new OptionsConfiguration());

        $executor = $this->executorWithClock([1000.0, 1000.0, 1060.0, 1060.0]);
        $result = $executor->execute($plan);

        $this->assertSame('1m 0s', $result->getJobResults()[0]->getExecutionTime());
    }

    /** @test */
    public function execution_time_just_under_sixty_seconds_uses_seconds_format(): void
    {
        // Pairs with the previous: at 59.99s the seconds branch must
        // still apply.
        $job = new CustomJob(new JobConfiguration('almost_min', 'custom', ['script' => 'true']));
        $plan = new FlowPlan('test', [$job], new OptionsConfiguration());

        $executor = $this->executorWithClock([1000.0, 1000.0, 1059.99, 1059.99]);
        $result = $executor->execute($plan);

        $this->assertSame('59.99s', $result->getJobResults()[0]->getExecutionTime());
    }

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

    /**
     * Build a FlowExecutor whose now() returns each tick in order. The
     * closure throws LogicException when the array is exhausted, so any
     * unexpected extra now() call surfaces as a loud test failure.
     *
     * The clock is injected through the constructor of an anonymous
     * subclass so static analysis sees a regular `FlowExecutor` and no
     * extra public method is needed for the test seam.
     *
     * @param float[] $ticks
     */
    private function executorWithClock(array $ticks): FlowExecutor
    {
        $idx = 0;
        $clock = function () use (&$ticks, &$idx) {
            if (!isset($ticks[$idx])) {
                throw new \LogicException("Clock exhausted at call #{$idx} (provided " . count($ticks) . ' ticks)');
            }
            return $ticks[$idx++];
        };

        return new class (new NullOutputHandler(), $clock) extends FlowExecutor {
            public function __construct(NullOutputHandler $output, callable $clock)
            {
                parent::__construct($output);
                $this->setClock($clock);
            }
        };
    }
}
