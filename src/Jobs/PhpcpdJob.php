<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Jobs;

class PhpcpdJob extends JobAbstract
{
    public const SUPPORTS_FAST = false;

    protected const ARGUMENT_MAP = [
        'exclude'    => ['flag' => '--exclude', 'type' => 'repeat'],
        'min-lines'  => ['type' => 'key_value'],
        'min-tokens' => ['type' => 'key_value'],
        'paths'      => ['type' => 'paths'],
    ];

    public static function getDefaultExecutable(): string
    {
        return 'phpcpd';
    }
}
