<?php

declare(strict_types=1);

namespace Tests\Unit\Execution\Memory;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\MemoryBudgetConfiguration;
use Wtyd\GitHooks\Execution\Memory\MemoryEvaluator;
use Wtyd\GitHooks\Execution\Memory\MemorySample;

class MemoryEvaluatorTest extends TestCase
{
    /** @test */
    public function tracks_per_job_peak_across_samples(): void
    {
        $evaluator = new MemoryEvaluator(true, 4);
        $evaluator->recordMemorySample(new MemorySample(1.0, ['phpstan' => 500, 'phpunit' => 200]));
        $evaluator->recordMemorySample(new MemorySample(2.0, ['phpstan' => 800, 'phpunit' => 100]));
        $evaluator->recordMemorySample(new MemorySample(3.0, ['phpstan' => 400, 'phpunit' => 300]));

        $this->assertSame(800, $evaluator->getJobPeak('phpstan'));
        $this->assertSame(300, $evaluator->getJobPeak('phpunit'));
        $this->assertNull($evaluator->getJobPeak('unknown'));
    }

    /** @test */
    public function tracks_flow_peak_with_attribution_at_the_max_sum_sample(): void
    {
        $evaluator = new MemoryEvaluator(true, 8);
        $evaluator->recordMemorySample(new MemorySample(1.0, ['a' => 200, 'b' => 200])); // sum 400
        $evaluator->recordMemorySample(new MemorySample(2.5, ['a' => 500, 'b' => 700])); // sum 1200 (peak)
        $evaluator->recordMemorySample(new MemorySample(3.5, ['a' => 100, 'b' => 100])); // sum 200

        $stats = $evaluator->buildStats();

        $this->assertSame(1200, $stats->getMemoryPeak());
        $this->assertSame(2.5, $stats->getMemoryPeakAtSecond());
        $this->assertSame(['a' => 500, 'b' => 700], $stats->getMemoryPeakAttribution());
    }

    /** @test */
    public function ignores_memory_samples_when_sampler_inactive(): void
    {
        $evaluator = new MemoryEvaluator(false, 4);
        $evaluator->recordMemorySample(new MemorySample(1.0, ['x' => 9999]));

        $this->assertNull($evaluator->getJobPeak('x'));
        $this->assertSame(0, $evaluator->buildStats()->getMemoryPeak());
    }

    /** @test */
    public function records_cores_peak_independently_of_memory_sampler(): void
    {
        $evaluator = new MemoryEvaluator(false, 8);
        $evaluator->recordCoresSample(0.1, 2, ['a', 'b']);
        $evaluator->recordCoresSample(0.5, 5, ['a', 'b', 'c']);
        $evaluator->recordCoresSample(0.9, 3, ['a']);

        $stats = $evaluator->buildStats();

        $this->assertSame(5, $stats->getCoresPeak());
        $this->assertSame(0.5, $stats->getCoresPeakAtSecond());
        $this->assertSame(['a', 'b', 'c'], $stats->getCoresPeakJobs());
        $this->assertSame(8, $stats->getCoresLimit());
    }

    /** @test */
    public function build_budget_state_returns_null_when_no_budget(): void
    {
        $evaluator = new MemoryEvaluator(true, 4);
        $evaluator->recordMemorySample(new MemorySample(1.0, ['x' => 500]));

        $this->assertNull($evaluator->buildBudgetState(null));
    }

    /** @test */
    public function build_budget_state_marks_warned_when_peak_crosses_warn_above(): void
    {
        $evaluator = new MemoryEvaluator(true, 4);
        $evaluator->recordMemorySample(new MemorySample(1.0, ['x' => 800]));

        $state = $evaluator->buildBudgetState(new MemoryBudgetConfiguration(500, 1500));

        $this->assertNotNull($state);
        $this->assertTrue($state->isWarned());
        $this->assertFalse($state->isFailed());
        $this->assertSame(800, $state->getPeakObserved());
    }

    /** @test */
    public function build_budget_state_marks_failed_when_peak_crosses_fail_above(): void
    {
        $evaluator = new MemoryEvaluator(true, 4);
        $evaluator->recordMemorySample(new MemorySample(2.0, ['x' => 1800]));

        $state = $evaluator->buildBudgetState(new MemoryBudgetConfiguration(500, 1500));

        $this->assertNotNull($state);
        $this->assertTrue($state->isWarned());
        $this->assertTrue($state->isFailed());
    }

    /** @test */
    public function is_kill_requested_when_peak_crosses_fail_above(): void
    {
        $evaluator = new MemoryEvaluator(true, 4);
        $evaluator->recordMemorySample(new MemorySample(0.5, ['x' => 800]));
        $this->assertFalse($evaluator->isKillRequested(new MemoryBudgetConfiguration(500, 1000)));

        $evaluator->recordMemorySample(new MemorySample(1.5, ['x' => 1200]));
        $this->assertTrue($evaluator->isKillRequested(new MemoryBudgetConfiguration(500, 1000)));
    }

    /** @test */
    public function is_kill_requested_returns_false_when_sampler_inactive(): void
    {
        $evaluator = new MemoryEvaluator(false, 4);
        $this->assertFalse($evaluator->isKillRequested(new MemoryBudgetConfiguration(500, 1000)));
    }

    /** @test */
    public function build_budget_state_returns_null_when_sampler_inactive_even_with_budget(): void
    {
        $evaluator = new MemoryEvaluator(false, 4);
        $this->assertNull($evaluator->buildBudgetState(new MemoryBudgetConfiguration(500, 1500)));
    }


    /** @test */
    public function is_kill_requested_returns_false_when_budget_is_null(): void
    {
        // Kills LogicalOr `||` -> `&&` mutant on the early-return guard
        // at line 100 AND the ReturnRemoval at line 101: with the And
        // mutant the function would proceed to call $budget->getFailAbove()
        // on a null budget — runtime error.
        $evaluator = new MemoryEvaluator(true, 4);

        $this->assertFalse($evaluator->isKillRequested(null));
    }

    /** @test */
    public function is_kill_requested_fires_at_exact_fail_above_boundary(): void
    {
        // Kills GreaterThanOrEqualTo `>=` -> `>` boundary at line 104.
        $evaluator = new MemoryEvaluator(true, 4);
        $evaluator->recordMemorySample(new MemorySample(1.0, ['x' => 1000]));

        $this->assertTrue($evaluator->isKillRequested(new MemoryBudgetConfiguration(500, 1000)));
    }

    /** @test */
    public function build_budget_state_warns_at_exact_warn_above_boundary(): void
    {
        // Kills GreaterThanOrEqualTo `>=` -> `>` boundary at line 120.
        $evaluator = new MemoryEvaluator(true, 4);
        $evaluator->recordMemorySample(new MemorySample(1.0, ['x' => 500]));

        $state = $evaluator->buildBudgetState(new MemoryBudgetConfiguration(500, 1500));

        $this->assertNotNull($state);
        $this->assertTrue($state->isWarned());
    }

    /** @test */
    public function build_budget_state_fails_at_exact_fail_above_boundary(): void
    {
        // Kills GreaterThanOrEqualTo `>=` -> `>` boundary at line 121.
        $evaluator = new MemoryEvaluator(true, 4);
        $evaluator->recordMemorySample(new MemorySample(1.0, ['x' => 1500]));

        $state = $evaluator->buildBudgetState(new MemoryBudgetConfiguration(500, 1500));

        $this->assertNotNull($state);
        $this->assertTrue($state->isFailed());
    }

    /** @test */
    public function build_budget_state_does_not_warn_when_warn_above_is_null(): void
    {
        // Kills LogicalAnd `&&` -> `||` mutant at line 120: with `||` the
        // expression `$warnAbove !== null || $this->memoryPeak >= $warnAbove`
        // would be true whenever peak >= 0, regardless of warnAbove being
        // undefined.
        $evaluator = new MemoryEvaluator(true, 4);
        $evaluator->recordMemorySample(new MemorySample(1.0, ['x' => 800]));

        $state = $evaluator->buildBudgetState(new MemoryBudgetConfiguration(null, 1500));

        $this->assertNotNull($state);
        $this->assertFalse($state->isWarned());
    }

    /** @test */
    public function memory_peak_is_not_overwritten_when_a_later_sample_ties(): void
    {
        // Kills GreaterThan `>` -> `>=` boundary at line 70 (memoryPeak
        // update guard). With `>=` mutant, a sample at the same total
        // would refresh the peak timestamp/attribution; with `>` (orig)
        // it must NOT.
        $evaluator = new MemoryEvaluator(true, 4);
        $evaluator->recordMemorySample(new MemorySample(1.0, ['a' => 600, 'b' => 400]));
        $evaluator->recordMemorySample(new MemorySample(2.0, ['c' => 500, 'd' => 500]));

        $state = $evaluator->buildBudgetState(new MemoryBudgetConfiguration(500, 1500));

        $this->assertSame(1000, $state->getPeakObserved());
        $this->assertSame(1.0, $state->getPeakAtSecond());
        $this->assertSame(['a' => 600, 'b' => 400], $state->getPeakAttribution());
    }

    /** @test */
    public function cores_peak_is_not_overwritten_when_later_sample_ties(): void
    {
        // Kills GreaterThan `>` -> `>=` boundary at line 82.
        $evaluator = new MemoryEvaluator(true, 4);
        $evaluator->recordCoresSample(1.0, 3, ['a', 'b', 'c']);
        $evaluator->recordCoresSample(2.0, 3, ['d', 'e', 'f']);

        $stats = $evaluator->buildStats();
        $this->assertSame(3, $stats->getCoresPeak());
        $this->assertSame(1.0, $stats->getCoresPeakAtSecond());
        $this->assertSame(['a', 'b', 'c'], $stats->getCoresPeakJobs());
    }

    /** @test */
    public function cores_peak_jobs_are_reindexed_dense(): void
    {
        // Kills UnwrapArrayValues at line 85: when the input is
        // associative, removing array_values would store the original
        // keys, breaking consumers that expect dense [0,1,2,...] indices.
        $evaluator = new MemoryEvaluator(true, 4);
        $evaluator->recordCoresSample(1.0, 2, ['job_a' => 'A', 'job_b' => 'B']);

        $stats = $evaluator->buildStats();
        $this->assertSame([0, 1], array_keys($stats->getCoresPeakJobs()));
    }

    /** @test */
    public function constructor_clamps_cores_limit_minimum_to_one(): void
    {
        // Kills DecrementInteger / IncrementInteger on `max(1, $coresLimit)`
        // at line 53. With `max(0, 0)` the cores limit would land at 0;
        // with `max(2, 0)` it would round up to 2 spuriously. This test
        // pins the lower bound at exactly 1 for input <= 0.
        $evaluator = new MemoryEvaluator(true, 0);

        $stats = $evaluator->buildStats();
        $this->assertSame(1, $stats->getCoresLimit());
    }

    /** @test */
    public function job_peak_is_not_recorded_for_zero_rss_sample(): void
    {
        // Kills DecrementInteger on the `?? 0` default at line 63: with
        // `?? -1` mutant, a zero-RSS sample would satisfy `0 > -1` and
        // persist a 0 peak; with the original, no peak is recorded for
        // a sample that doesn't exceed 0.
        $evaluator = new MemoryEvaluator(true, 4);
        $evaluator->recordMemorySample(new MemorySample(1.0, ['x' => 0]));

        $this->assertNull($evaluator->getJobPeak('x'));
    }
}
