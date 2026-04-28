<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution\Memory;

/**
 * Immutable snapshot of a single sampling tick: the wall-clock offset since
 * flow start and the RSS reading per job in flight at that instant.
 */
final class MemorySample
{
    private float $atSecond;

    /** @var array<string, int> jobName → RSS in MB */
    private array $perJob;

    /**
     * @param array<string, int> $perJob
     */
    public function __construct(float $atSecond, array $perJob)
    {
        $this->atSecond = $atSecond;
        $this->perJob = $perJob;
    }

    public function getAtSecond(): float
    {
        return $this->atSecond;
    }

    /**
     * @return array<string, int>
     */
    public function getPerJob(): array
    {
        return $this->perJob;
    }

    /**
     * Sum of RSS across all jobs in flight at this sample (the value the
     * flow memory-budget compares against warn-above / fail-above).
     */
    public function getTotal(): int
    {
        return array_sum($this->perJob);
    }
}
