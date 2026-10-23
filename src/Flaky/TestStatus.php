<?php

declare(strict_types=1);

namespace Wtyd\Alfil\Flaky;

/**
 * Outcome of one testcase in one junit file, already normalised for flap
 * detection: a failure and an error both mean "not green", a skip and a
 * warning neither pass nor fail.
 */
final class TestStatus
{
    public const GREEN = 'green';

    public const NONGREEN = 'nongreen';

    public const NEUTRAL = 'neutral';

    /**
     * Rank used when one pass reports the same test twice: the worst outcome
     * wins, so a duplicate can never hide a failure.
     */
    private const SEVERITY = [self::NEUTRAL => 0, self::GREEN => 1, self::NONGREEN => 2];

    private function __construct()
    {
    }

    public static function worst(string $first, string $second): string
    {
        return self::SEVERITY[$second] > self::SEVERITY[$first] ? $second : $first;
    }
}
