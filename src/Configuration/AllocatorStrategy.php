<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Configuration;

class AllocatorStrategy
{
    public const FIFO = 'fifo';
    public const GREEDY = 'greedy';

    /** @var string[] */
    public const ALL = [self::FIFO, self::GREEDY];

    public static function isValid(string $strategy): bool
    {
        return in_array($strategy, self::ALL, true);
    }
}
