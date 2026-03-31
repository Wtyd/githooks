<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Jobs;

class PhpunitJob extends JobAbstract
{
    protected const ARGUMENT_MAP = [
        'group'         => ['flag' => '--group', 'type' => 'value', 'separator' => ' '],
        'exclude-group' => ['flag' => '--exclude-group', 'type' => 'value', 'separator' => ' '],
        'filter'        => ['flag' => '--filter', 'type' => 'value', 'separator' => ' '],
        'configuration' => ['flag' => '-c', 'type' => 'value', 'separator' => ' '],
        'config'        => ['flag' => '-c', 'type' => 'value', 'separator' => ' '],
        'log-junit'     => ['flag' => '--log-junit', 'type' => 'value', 'separator' => ' '],
    ];

    public static function getDefaultExecutable(): string
    {
        return 'phpunit';
    }
}
