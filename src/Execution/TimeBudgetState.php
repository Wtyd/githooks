<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

/**
 * Post-hoc state of a flow's `time-budget`. Built by FlowExecutor after the
 * run by summing the duration of executed (non-skipped) jobs and comparing
 * it against the configured `warn-after` / `fail-after`.
 *
 * - `warned` is true when the sum reaches or exceeds `warnAfter` and does not
 *   reach `failAfter`.
 * - `failed` is true when the sum reaches or exceeds `failAfter`.
 *
 * When neither budget is configured (no time-budget on the run), FlowExecutor
 * does not build this VO at all (FlowResult holds null).
 */
final class TimeBudgetState
{
    private ?int $warnAfter;

    private ?int $failAfter;

    private float $totalJobDuration;

    private bool $warned;

    private bool $failed;

    public function __construct(
        ?int $warnAfter,
        ?int $failAfter,
        float $totalJobDuration,
        bool $warned,
        bool $failed
    ) {
        $this->warnAfter = $warnAfter;
        $this->failAfter = $failAfter;
        $this->totalJobDuration = $totalJobDuration;
        $this->warned = $warned;
        $this->failed = $failed;
    }

    public function getWarnAfter(): ?int
    {
        return $this->warnAfter;
    }

    public function getFailAfter(): ?int
    {
        return $this->failAfter;
    }

    public function getTotalJobDuration(): float
    {
        return $this->totalJobDuration;
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
