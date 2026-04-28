<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution\Memory;

use Wtyd\GitHooks\Configuration\MemoryThreshold;

/**
 * Stateless evaluator of the per-job memory threshold. Encapsulates the
 * matrix in spec §4.5.1: NONE | WARNED | FAILED + an human-readable reason.
 *
 * Symmetric to JobResult::THRESHOLD_* states for time-budget so the JSON
 * v2 keeps shape parity (REQ-041).
 */
final class MemoryThresholdEvaluator
{
    public const STATE_NONE = 0;

    public const STATE_WARNED = 1;

    public const STATE_FAILED = 2;

    public const REASON_WARN = 'exceeded warn-above';

    public const REASON_FAIL = 'exceeded fail-above';

    /**
     * @return array{state: int, reason: ?string}
     */
    public static function evaluate(int $peakRss, MemoryThreshold $threshold): array
    {
        $failAbove = $threshold->getFailAbove();
        if ($failAbove !== null && $peakRss >= $failAbove) {
            return ['state' => self::STATE_FAILED, 'reason' => self::REASON_FAIL];
        }

        $warnAbove = $threshold->getWarnAbove();
        if ($warnAbove !== null && $peakRss >= $warnAbove) {
            return ['state' => self::STATE_WARNED, 'reason' => self::REASON_WARN];
        }

        return ['state' => self::STATE_NONE, 'reason' => null];
    }
}
