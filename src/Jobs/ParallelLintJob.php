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
        'json'    => ['flag' => '--json', 'type' => 'boolean'],
        'paths'   => ['type' => 'paths'],
    ];

    public function supportsStructuredOutput(): bool
    {
        return true;
    }

    public function applyStructuredOutputFormat(): bool
    {
        $this->args['json'] = true;
        return true;
    }

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
