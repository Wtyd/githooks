<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Jobs;

class RectorJob extends JobAbstract
{
    public const SUPPORTS_FAST = true;

    protected const ARGUMENT_MAP = [
        'config'          => ['flag' => '--config', 'type' => 'value'],
        'dry-run'         => ['flag' => '--dry-run', 'type' => 'boolean'],
        'clear-cache'     => ['flag' => '--clear-cache', 'type' => 'boolean'],
        'no-progress-bar' => ['flag' => '--no-progress-bar', 'type' => 'boolean'],
        'paths'           => ['type' => 'paths'],
    ];

    public static function getDefaultExecutable(): string
    {
        return 'rector';
    }

    protected function getSubcommand(): string
    {
        return 'process';
    }

    /**
     * In non-dry-run mode, exit code 0 means the tool ran successfully and may
     * have applied refactorings. Re-staging is safe (idempotent).
     * In dry-run mode, no files are changed.
     */
    public function isFixApplied(int $exitCode): bool
    {
        if (!empty($this->args['dry-run'])) {
            return false;
        }

        return $exitCode === 0;
    }

    /** @return string[] */
    public function getCachePaths(): array
    {
        return ['/tmp/rector'];
    }
}
