<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Jobs;

class PhpstanJob extends JobAbstract
{
    protected const ARGUMENT_MAP = [
        'config'             => ['flag' => '-c', 'type' => 'value', 'separator' => ' '],
        'level'              => ['flag' => '-l', 'type' => 'value', 'separator' => ' '],
        'memory-limit'       => ['flag' => '--memory-limit', 'type' => 'value'],
        'error-format'       => ['flag' => '--error-format', 'type' => 'value'],
        'no-progress'        => ['flag' => '--no-progress', 'type' => 'boolean'],
        'clear-result-cache' => ['flag' => '--clear-result-cache', 'type' => 'boolean'],
        'paths'              => ['type' => 'paths'],
    ];

    public static function getDefaultExecutable(): string
    {
        return 'phpstan';
    }

    protected function getSubcommand(): string
    {
        return 'analyse';
    }
}
