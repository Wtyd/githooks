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
}
