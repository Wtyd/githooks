<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

class ExecutionMode
{
    public const FULL = 'full';
    public const FAST = 'fast';
    public const FAST_BRANCH = 'fast-branch';
    public const FAST_DIRTY = 'fast-dirty';

    /** @var string[] */
    public const ALL = [self::FULL, self::FAST, self::FAST_BRANCH, self::FAST_DIRTY];

    public static function isValid(string $mode): bool
    {
        return in_array($mode, self::ALL, true);
    }
}
