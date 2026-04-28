<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

/**
 * Final root-level state of the flow memory-budget evaluation. Built by the
 * FlowExecutor from the MemoryEvaluator and surfaced on the FlowResult so
 * the JSON v2 (REQ-039) and the text output can render `memoryBudget`.
 *
 * Symmetric to TimeBudgetState: explicit-null pattern at consumer side
 * (JsonResultFormatter emits the field as null when no budget is active).
 *
 * @SuppressWarnings(PHPMD.TooManyFields) Immutable value object — fields
 *   mirror the JSON v2 contract.
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Same reason.
 */
final class MemoryBudgetState
{
    private ?int $warnAbove;

    private ?int $failAbove;

    private int $peakObserved;

    private float $peakAtSecond;

    /** @var array<string, int> jobName → RSS in MB at the peak sample */
    private array $peakAttribution;

    private bool $warned;

    private bool $failed;

    /**
     * @param array<string, int> $peakAttribution
     */
    public function __construct(
        ?int $warnAbove,
        ?int $failAbove,
        int $peakObserved,
        float $peakAtSecond,
        array $peakAttribution,
        bool $warned,
        bool $failed
    ) {
        $this->warnAbove = $warnAbove;
        $this->failAbove = $failAbove;
        $this->peakObserved = $peakObserved;
        $this->peakAtSecond = $peakAtSecond;
        $this->peakAttribution = $peakAttribution;
        $this->warned = $warned;
        $this->failed = $failed;
    }

    public function getWarnAbove(): ?int
    {
        return $this->warnAbove;
    }

    public function getFailAbove(): ?int
    {
        return $this->failAbove;
    }

    public function getPeakObserved(): int
    {
        return $this->peakObserved;
    }

    public function getPeakAtSecond(): float
    {
        return $this->peakAtSecond;
    }

    /** @return array<string, int> */
    public function getPeakAttribution(): array
    {
        return $this->peakAttribution;
    }

    public function isWarned(): bool
    {
        return $this->warned;
    }

    public function isFailed(): bool
    {
        return $this->failed;
    }
}
