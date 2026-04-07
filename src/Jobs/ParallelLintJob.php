<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Jobs;

use Wtyd\GitHooks\Execution\ThreadCapability;

class ParallelLintJob extends JobAbstract
{
    public const SUPPORTS_FAST = true;

    protected const ARGUMENT_MAP = [
        'jobs'    => ['flag' => '-j', 'type' => 'value', 'separator' => ' '],
        'exclude' => ['flag' => '--exclude', 'type' => 'repeat'],
        'paths'   => ['type' => 'paths'],
    ];

    public static function getDefaultExecutable(): string
    {
        return 'parallel-lint';
    }

    public function getThreadCapability(): ?ThreadCapability
    {
        $current = isset($this->args['jobs']) ? (int) $this->args['jobs'] : 10;
        return new ThreadCapability('jobs', $current);
    }

    public function applyThreadLimit(int $threads): void
    {
        $this->args['jobs'] = $threads;
    }
}
