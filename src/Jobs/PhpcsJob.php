<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Jobs;

use Wtyd\GitHooks\Execution\ThreadCapability;

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

    public function getThreadCapability(): ?ThreadCapability
    {
        $current = isset($this->args['parallel']) ? (int) $this->args['parallel'] : 1;
        return new ThreadCapability('parallel', $current);
    }

    public function applyThreadLimit(int $threads): void
    {
        $this->args['parallel'] = $threads;
    }
}
