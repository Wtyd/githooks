<?php

declare(strict_types=1);

namespace Tests\Unit\Execution\Memory;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\MemoryThreshold;
use Wtyd\GitHooks\Execution\Memory\MemoryThresholdEvaluator;

class MemoryThresholdEvaluatorTest extends TestCase
{
    /** @test */
    public function returns_none_when_below_warn_above(): void
    {
        $threshold = MemoryThreshold::fromInt(2000);

        $eval = MemoryThresholdEvaluator::evaluate(1000, $threshold);

        $this->assertSame(MemoryThresholdEvaluator::STATE_NONE, $eval['state']);
        $this->assertNull($eval['reason']);
    }

    /** @test */
    public function returns_warned_when_above_warn_only(): void
    {
        $threshold = MemoryThreshold::fromInt(2000);

        $eval = MemoryThresholdEvaluator::evaluate(2500, $threshold);

        $this->assertSame(MemoryThresholdEvaluator::STATE_WARNED, $eval['state']);
        $this->assertSame(MemoryThresholdEvaluator::REASON_WARN, $eval['reason']);
    }

    /** @test */
    public function returns_warned_at_exact_warn_above(): void
    {
        $threshold = MemoryThreshold::fromInt(2000);

        $eval = MemoryThresholdEvaluator::evaluate(2000, $threshold);

        $this->assertSame(MemoryThresholdEvaluator::STATE_WARNED, $eval['state']);
    }

    /** @test */
    public function returns_failed_when_above_fail(): void
    {
        $rawArray = ['warn-above' => 1000, 'fail-above' => 2000];
        $threshold = MemoryThreshold::fromArray($rawArray, new \Wtyd\GitHooks\Configuration\ValidationResult(), 'job');
        $this->assertNotNull($threshold);

        $eval = MemoryThresholdEvaluator::evaluate(2200, $threshold);

        $this->assertSame(MemoryThresholdEvaluator::STATE_FAILED, $eval['state']);
        $this->assertSame(MemoryThresholdEvaluator::REASON_FAIL, $eval['reason']);
    }

    /** @test */
    public function fail_takes_precedence_over_warn_when_both_cross(): void
    {
        $threshold = MemoryThreshold::fromArray(
            ['warn-above' => 1000, 'fail-above' => 2000],
            new \Wtyd\GitHooks\Configuration\ValidationResult(),
            'job'
        );

        $eval = MemoryThresholdEvaluator::evaluate(2500, $threshold);

        $this->assertSame(MemoryThresholdEvaluator::STATE_FAILED, $eval['state']);
        $this->assertSame(MemoryThresholdEvaluator::REASON_FAIL, $eval['reason']);
    }

    /** @test */
    public function returns_none_when_below_warn_with_only_fail_configured(): void
    {
        $threshold = MemoryThreshold::fromArray(
            ['fail-above' => 2000],
            new \Wtyd\GitHooks\Configuration\ValidationResult(),
            'job'
        );

        $eval = MemoryThresholdEvaluator::evaluate(1500, $threshold);

        $this->assertSame(MemoryThresholdEvaluator::STATE_NONE, $eval['state']);
    }
}
