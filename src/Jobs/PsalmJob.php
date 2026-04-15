<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Jobs;

use Wtyd\GitHooks\Execution\ThreadCapability;

class PsalmJob extends JobAbstract
{
    public const SUPPORTS_FAST = true;

    protected const ARGUMENT_MAP = [
        'config'        => ['type' => 'key_value'],
        'memory-limit'  => ['type' => 'key_value'],
        'threads'       => ['type' => 'key_value'],
        'output-format' => ['type' => 'key_value'],
        'plugin'        => ['type' => 'key_value'],
        'use-baseline'  => ['type' => 'key_value'],
        'report'        => ['type' => 'key_value'],
        'no-diff'       => ['flag' => '--no-diff', 'type' => 'boolean'],
        'paths'         => ['type' => 'paths'],
    ];

    public static function getDefaultExecutable(): string
    {
        return 'psalm';
    }

    public function supportsStructuredOutput(): bool
    {
        return true;
    }

    public function applyStructuredOutputFormat(): bool
    {
        $this->args['output-format'] = 'json';
        return true;
    }

    /** @return string[] */
    public function getCachePaths(): array
    {
        return ['.psalm/cache/'];
    }

    public function getThreadCapability(): ?ThreadCapability
    {
        $current = isset($this->args['threads']) ? (int) $this->args['threads'] : 1;
        return new ThreadCapability('threads', $current);
    }

    public function applyThreadLimit(int $threads): void
    {
        $this->args['threads'] = (string) $threads;
    }
}
