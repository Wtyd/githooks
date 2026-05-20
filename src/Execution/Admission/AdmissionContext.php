<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution\Admission;

use Wtyd\GitHooks\Jobs\JobAbstract;

/**
 * Snapshot of live resources passed to AdmissionStrategy::pickNext().
 *
 * `coresFree` is always present. `memoryFree` is the remaining MB under
 * the flow `memory-budget.warn-above` minus the active reservations
 * (REQ-009); null means "no flow memory budget declared" → 1D mode (the
 * strategy ignores the memory axis).
 *
 * `coresByJob` and `memoryReserveByJob` are precomputed at pool construction
 * so the strategy can answer "does this specific job fit?" in O(1) without
 * peeking into JobAbstract internals.
 */
final class AdmissionContext
{
    public int $coresFree;

    public ?int $memoryFree;

    /** @var array<string, int> jobName → cores it would consume when admitted */
    public array $coresByJob;

    /** @var array<string, ?int> jobName → memory reservation in MB (null when not declared in short form) */
    public array $memoryReserveByJob;

    /** @var array<string, string[]> FEAT-3: jobName → list of jobs it depends on within the same flow */
    public array $needsByJob;

    /** @var string[] FEAT-3: jobs that completed successfully (their dependents are unblocked) */
    public array $completedJobs;

    /** @var string[] FEAT-3: jobs whose process failed (their dependents propagate the skip) */
    public array $failedJobs;

    /** @var string[] FEAT-3: jobs that were skipped (only-files, fail-fast, or upstream propagation) */
    public array $skippedJobs;

    /**
     * @param array<string, int>  $coresByJob
     * @param array<string, ?int> $memoryReserveByJob
     * @param array<string, string[]> $needsByJob
     * @param string[] $completedJobs
     * @param string[] $failedJobs
     * @param string[] $skippedJobs
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Resource axes (cores/memory) plus FEAT-3 dependency tracking.
     */
    public function __construct(
        int $coresFree,
        ?int $memoryFree,
        array $coresByJob,
        array $memoryReserveByJob,
        array $needsByJob = [],
        array $completedJobs = [],
        array $failedJobs = [],
        array $skippedJobs = []
    ) {
        $this->coresFree = $coresFree;
        $this->memoryFree = $memoryFree;
        $this->coresByJob = $coresByJob;
        $this->memoryReserveByJob = $memoryReserveByJob;
        $this->needsByJob = $needsByJob;
        $this->completedJobs = $completedJobs;
        $this->failedJobs = $failedJobs;
        $this->skippedJobs = $skippedJobs;
    }

    /**
     * Whether the given queued job fits in the currently available resources.
     * In 1D mode (memoryFree === null), the memory axis is ignored even if
     * the job declared a reservation — the budget gate is what activates 2D.
     */
    public function fits(JobAbstract $job): bool
    {
        $name = $job->getName();
        $cores = $this->coresByJob[$name] ?? 1;
        if ($cores > $this->coresFree) {
            return false;
        }

        if ($this->memoryFree === null) {
            return true;
        }

        $memoryReserve = $this->memoryReserveByJob[$name] ?? 0;
        return $memoryReserve <= $this->memoryFree;
    }

    /**
     * FEAT-3: a job is ready when every declared `needs` target completed
     * successfully. Failed or skipped needs make the job non-ready forever
     * (the pool will drain it as skipped-by-dep before the strategy sees it).
     */
    public function isJobReady(JobAbstract $job): bool
    {
        $needs = $this->needsByJob[$job->getName()] ?? [];
        if ($needs === []) {
            return true;
        }
        foreach ($needs as $dep) {
            if (!in_array($dep, $this->completedJobs, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Needs that are still pending (neither completed nor failed/skipped).
     * Used by the pool to emit `onJobWaiting` events for the dashboard — a
     * blocker that is already failed/skipped is a propagation candidate, not
     * a waiting candidate.
     *
     * @return string[]
     */
    public function getBlockingNeeds(JobAbstract $job): array
    {
        $needs = $this->needsByJob[$job->getName()] ?? [];
        $blocking = [];
        foreach ($needs as $dep) {
            if (in_array($dep, $this->completedJobs, true)) {
                continue;
            }
            if (in_array($dep, $this->failedJobs, true)) {
                continue;
            }
            if (in_array($dep, $this->skippedJobs, true)) {
                continue;
            }
            $blocking[] = $dep;
        }
        return $blocking;
    }

    /**
     * Needs that reached a terminal non-success state (failed or skipped).
     * Used by the pool to build the `skipReason` when propagating an upstream
     * fail/skip down the DAG. Order preserves the declaration order of `needs`.
     *
     * @return array<string, string>  jobName → 'failed' | 'skipped'
     */
    public function getFailedOrSkippedNeeds(JobAbstract $job): array
    {
        $needs = $this->needsByJob[$job->getName()] ?? [];
        $result = [];
        foreach ($needs as $dep) {
            if (in_array($dep, $this->failedJobs, true)) {
                $result[$dep] = 'failed';
            } elseif (in_array($dep, $this->skippedJobs, true)) {
                $result[$dep] = 'skipped';
            }
        }
        return $result;
    }
}
