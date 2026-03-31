<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Jobs;

class ParallelLintJob extends JobAbstract
{
    protected const ARGUMENT_MAP = [
        'jobs'    => ['flag' => '-j', 'type' => 'value', 'separator' => ' '],
        'exclude' => ['flag' => '--exclude', 'type' => 'repeat'],
        'paths'   => ['type' => 'paths'],
    ];

    public static function getDefaultExecutable(): string
    {
        return 'parallel-lint';
    }
}
