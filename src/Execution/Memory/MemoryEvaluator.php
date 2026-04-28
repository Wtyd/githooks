<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution\Memory;

use Wtyd\GitHooks\Configuration\MemoryBudgetConfiguration;
use Wtyd\GitHooks\Execution\MemoryBudgetState;

/**
 * Aggregates memory samples across the flow and answers the two questions
 * the FlowExecutor needs to ask each tick:
 *
 *   - "Is the flow memory-budget about to be crossed?" (kill decision)
 *   - "What is the current peakObserved/peakAttribution?" (final state)
 *
 * Cores stats are fed in parallel via recordCoresSample() because they are
 * deterministic from the schedule (no /proc reading needed) and useful in
 * --stats even when the RSS sampler is unavailable (REQ-040 cores sub-block).
 *
 * Only the peak sample is retained (per REQ-025): keeping every sample
 * would balloon memory on long flows for no value.
 *
 * @SuppressWarnings(PHPMD.TooManyFields) State of the flow execution; each field
 *   tracks one orthogonal dimension of the peak observation.
 */
final class MemoryEvaluator
{
    private bool $samplerActive;

    private int $coresLimit;

    private int $memoryPeak = 0;

    private float $memoryPeakAtSecond = 0.0;

    /** @var array<string, int> snapshot of per-job RSS at the memory peak */
    private array $memoryPeakAttribution = [];

    /** @var array<string, int> jobName → individual RSS peak across the job's lifetime */
    private array $jobPeaks = [];

    private int $coresPeak = 0;

    private float $coresPeakAtSecond = 0.0;

    /** @var string[] jobs in flight at the cores peak */
    private array $coresPeakJobs = [];

    public function __construct(bool $samplerActive, int $coresLimit)
    {
        $this->samplerActive = $samplerActive;
        $this->coresLimit = max(1, $coresLimit);
    }

    public function recordMemorySample(MemorySample $sample): void
    {
        if (!$this->samplerActive) {
            return;
        }

        foreach ($sample->getPerJob() as $jobName => $rss) {
            $previous = $this->jobPeaks[$jobName] ?? 0;
            if ($rss > $previous) {
                $this->jobPeaks[$jobName] = $rss;
            }
        }

        $total = $sample->getTotal();
        if ($total > $this->memoryPeak) {
            $this->memoryPeak = $total;
            $this->memoryPeakAtSecond = $sample->getAtSecond();
            $this->memoryPeakAttribution = $sample->getPerJob();
        }
    }

    /**
     * @param string[] $jobsInFlight job names in flight at this instant
     */
    public function recordCoresSample(float $atSecond, int $coresInUse, array $jobsInFlight): void
    {
        if ($coresInUse > $this->coresPeak) {
            $this->coresPeak = $coresInUse;
            $this->coresPeakAtSecond = $atSecond;
            $this->coresPeakJobs = array_values($jobsInFlight);
        }
    }

    public function getJobPeak(string $jobName): ?int
    {
        return $this->jobPeaks[$jobName] ?? null;
    }

    /**
     * Returns true the moment the flow memory-budget's fail-above is crossed.
     * Used by the executor to fire the kill path (REQ-013).
     */
    public function isKillRequested(?MemoryBudgetConfiguration $budget): bool
    {
        if (!$this->samplerActive || $budget === null) {
            return false;
        }
        $failAbove = $budget->getFailAbove();
        return $failAbove !== null && $this->memoryPeak >= $failAbove;
    }

    public function buildBudgetState(?MemoryBudgetConfiguration $budget): ?MemoryBudgetState
    {
        if ($budget === null) {
            return null;
        }
        if (!$this->samplerActive) {
            // Budget declared but no sampler available — null state, the
            // executor already emitted a one-time degradation warning.
            return null;
        }

        $warnAbove = $budget->getWarnAbove();
        $failAbove = $budget->getFailAbove();
        $warned = $warnAbove !== null && $this->memoryPeak >= $warnAbove;
        $failed = $failAbove !== null && $this->memoryPeak >= $failAbove;

        return new MemoryBudgetState(
            $warnAbove,
            $failAbove,
            $this->memoryPeak,
            $this->memoryPeakAtSecond,
            $this->memoryPeakAttribution,
            $warned,
            $failed
        );
    }

    public function buildStats(): MemoryStats
    {
        return new MemoryStats(
            $this->samplerActive,
            $this->memoryPeak,
            $this->memoryPeakAtSecond,
            $this->memoryPeakAttribution,
            $this->jobPeaks,
            $this->coresLimit,
            $this->coresPeak,
            $this->coresPeakAtSecond,
            $this->coresPeakJobs
        );
    }
}
