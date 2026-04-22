<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Jobs;

use Wtyd\GitHooks\Execution\ThreadCapability;

class ParatestJob extends PhpunitJob
{
    protected const ARGUMENT_MAP = [
        'group'         => ['flag' => '--group', 'type' => 'value', 'separator' => ' '],
        'exclude-group' => ['flag' => '--exclude-group', 'type' => 'value', 'separator' => ' '],
        'filter'        => ['flag' => '--filter', 'type' => 'value', 'separator' => ' '],
        'configuration' => ['flag' => '-c', 'type' => 'value', 'separator' => ' '],
        'config'        => ['flag' => '-c', 'type' => 'value', 'separator' => ' '],
        'log-junit'     => ['flag' => '--log-junit', 'type' => 'value', 'separator' => ' '],
        'processes'     => ['flag' => '--processes', 'type' => 'value', 'separator' => '='],
    ];

    public static function getDefaultExecutable(): string
    {
        return 'paratest';
    }

    public function getThreadCapability(): ?ThreadCapability
    {
        $current = isset($this->args['processes']) ? (int) $this->args['processes'] : 4;
        return new ThreadCapability('processes', $current);
    }

    public function applyThreadLimit(int $threads): void
    {
        $this->args['processes'] = $threads;
    }
}
