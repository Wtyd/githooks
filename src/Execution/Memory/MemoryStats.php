<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution\Memory;

/**
 * Final aggregate stats for a flow run. Built by MemoryEvaluator from the
 * stream of samples it consumed during execution. Carries both the memory
 * axis (sampled) and the cores axis (deterministic from the schedule).
 *
 * Surfaced on the FlowResult and read by JsonResultFormatter and
 * StatsTableRenderer to expose the `--stats` view (REQ-040, REQ-036).
 *
 * @SuppressWarnings(PHPMD.TooManyFields) Immutable value object — every field
 *   describes one facet of the schedule's resource usage.
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Same reason.
 */
final class MemoryStats
{
    private bool $samplerActive;

    private int $memoryPeak;

    private float $memoryPeakAtSecond;

    /** @var array<string, int> jobName → RSS in MB at the peak sample */
    private array $memoryPeakAttribution;

    /** @var array<string, int> jobName → individual RSS peak across the job's lifetime (MB) */
    private array $jobPeaks;

    private int $coresLimit;

    private int $coresPeak;

    private float $coresPeakAtSecond;

    /** @var string[] job names in flight when the cores peak occurred */
    private array $coresPeakJobs;

    /**
     * @param array<string, int> $memoryPeakAttribution
     * @param array<string, int> $jobPeaks
     * @param string[]           $coresPeakJobs
     */
    public function __construct(
        bool $samplerActive,
        int $memoryPeak,
        float $memoryPeakAtSecond,
        array $memoryPeakAttribution,
        array $jobPeaks,
        int $coresLimit,
        int $coresPeak,
        float $coresPeakAtSecond,
        array $coresPeakJobs
    ) {
        $this->samplerActive = $samplerActive;
        $this->memoryPeak = $memoryPeak;
        $this->memoryPeakAtSecond = $memoryPeakAtSecond;
        $this->memoryPeakAttribution = $memoryPeakAttribution;
        $this->jobPeaks = $jobPeaks;
        $this->coresLimit = $coresLimit;
        $this->coresPeak = $coresPeak;
        $this->coresPeakAtSecond = $coresPeakAtSecond;
        $this->coresPeakJobs = $coresPeakJobs;
    }

    public function isSamplerActive(): bool
    {
        return $this->samplerActive;
    }

    public function getMemoryPeak(): int
    {
        return $this->memoryPeak;
    }

    public function getMemoryPeakAtSecond(): float
    {
        return $this->memoryPeakAtSecond;
    }

    /** @return array<string, int> */
    public function getMemoryPeakAttribution(): array
    {
        return $this->memoryPeakAttribution;
    }

    /** @return array<string, int> */
    public function getJobPeaks(): array
    {
        return $this->jobPeaks;
    }

    public function getJobPeak(string $jobName): ?int
    {
        return $this->jobPeaks[$jobName] ?? null;
    }

    public function getCoresLimit(): int
    {
        return $this->coresLimit;
    }

    public function getCoresPeak(): int
    {
        return $this->coresPeak;
    }

    public function getCoresPeakAtSecond(): float
    {
        return $this->coresPeakAtSecond;
    }

    /** @return string[] */
    public function getCoresPeakJobs(): array
    {
        return $this->coresPeakJobs;
    }
}
