<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution\Admission;

/**
 * First-fit admission scanning the entire queue. Returns the first index
 * (in declaration order) whose job fits the current resources (REQ-018).
 *
 * Cannot starve jobs because the queue is closed and finite: every job is
 * known up-front, none arrives during the run, so a heavy job blocked by
 * memory or cores will eventually run when the lighter ones complete.
 *
 * Applies to both axes simultaneously when in 2D mode and to the cores axis
 * alone in 1D mode (REQ-019).
 */
final class GreedyAdmission implements AdmissionStrategy
{
    public function pickNext(array $queue, AdmissionContext $context): ?int
    {
        foreach ($queue as $index => $job) {
            if ($context->fits($job)) {
                return $index;
            }
        }

        return null;
    }
}
