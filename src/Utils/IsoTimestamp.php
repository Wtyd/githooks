<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Utils;

use DateTimeImmutable;

/**
 * Formats a `microtime(true)` float as an ISO-8601 timestamp with millisecond
 * precision (FEAT-14: per-job and flow `startedAt`/`endedAt`, diagnostics header).
 */
final class IsoTimestamp
{
    public static function fromMicrotime(float $micro): string
    {
        $dateTime = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $micro));
        if ($dateTime === false) {
            return date('c');
        }
        return $dateTime->format(DATE_RFC3339_EXTENDED);
    }
}
