<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution\Admission;

/**
 * Strict declaration-order admission. The queue head is the only candidate
 * each tick: if it does not fit, every other queued job waits — even if
 * any of them would fit (REQ-017).
 *
 * Predictable for debugging and CI parity with the pre-3.3 pool. Pair with
 * GreedyAdmission when the flow has a heavy job declared late and others
 * could legitimately overlap.
 */
final class FifoAdmission implements AdmissionStrategy
{
    public function pickNext(array $queue, AdmissionContext $context): ?int
    {
        if (empty($queue)) {
            return null;
        }

        $headIndex = (int) array_key_first($queue);
        $head = $queue[$headIndex];
        // FEAT-3: dependency gate runs before the resource fit check. If the
        // head is still waiting for a `needs` target, the FIFO ordering
        // requires us to block — even if subsequent jobs in the queue could
        // be admitted. The pool drains heads with failed/skipped deps before
        // each tick, so reaching this point with non-ready needs means we
        // are genuinely waiting for a still-pending upstream job.
        if (!$context->isJobReady($head)) {
            return null;
        }
        return $context->fits($head) ? $headIndex : null;
    }
}
