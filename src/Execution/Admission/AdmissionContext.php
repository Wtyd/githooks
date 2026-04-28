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

    /**
     * @param array<string, int>  $coresByJob
     * @param array<string, ?int> $memoryReserveByJob
     */
    public function __construct(
        int $coresFree,
        ?int $memoryFree,
        array $coresByJob,
        array $memoryReserveByJob
    ) {
        $this->coresFree = $coresFree;
        $this->memoryFree = $memoryFree;
        $this->coresByJob = $coresByJob;
        $this->memoryReserveByJob = $memoryReserveByJob;
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
}
