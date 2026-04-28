<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution\Admission;

use Wtyd\GitHooks\Jobs\JobAbstract;

/**
 * Decides which queued job (if any) to admit next given the live resource
 * snapshot. The ProcessPool calls pickNext() in a loop until either the
 * queue is empty or the strategy returns null.
 *
 * Implementations are pure: they do not mutate the queue nor the context.
 * The pool removes the chosen job from the queue and updates the tracker.
 *
 * Strategies are knobs orthogonal to the bin-packing dimension: both FIFO
 * and greedy work in 1D (cores only) and 2D (cores + memory) — the mode
 * is determined by AdmissionContext.memoryFree being null or not (REQ-019).
 */
interface AdmissionStrategy
{
    /**
     * @param array<int, JobAbstract> $queue Sequentially-indexed job queue.
     *        Indices match the original declaration order at the time the
     *        queue was built.
     * @return int|null Index in $queue of the job to admit, or null when no
     *         job fits the current resources.
     */
    public function pickNext(array $queue, AdmissionContext $context): ?int;
}
