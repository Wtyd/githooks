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
        return $context->fits($queue[$headIndex]) ? $headIndex : null;
    }
}
