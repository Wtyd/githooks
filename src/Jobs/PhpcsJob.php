<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Jobs;

class PhpcsJob extends JobAbstract
{
    protected const ARGUMENT_MAP = [
        'standard'         => ['flag' => '--standard', 'type' => 'value'],
        'ignore'           => ['flag' => '--ignore', 'type' => 'csv'],
        'error-severity'   => ['flag' => '--error-severity', 'type' => 'value'],
        'warning-severity' => ['flag' => '--warning-severity', 'type' => 'value'],
        'cache'            => ['flag' => '--cache', 'type' => 'boolean'],
        'no-cache'         => ['flag' => '--no-cache', 'type' => 'boolean'],
        'report'           => ['flag' => '--report', 'type' => 'value'],
        'parallel'         => ['flag' => '--parallel', 'type' => 'value'],
        'paths'            => ['type' => 'paths'],
    ];

    public static function getDefaultExecutable(): string
    {
        return 'phpcs';
    }
}
