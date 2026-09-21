<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution\Process;

/**
 * Fallback tree for platforms with no supported way to walk processes
 * (Windows, Linux without /proc). Sees no descendants and nobody alive;
 * ProcessTerminator reads isAvailable() and falls back to Process::stop()
 * — which on Windows already kills the tree via `taskkill /T`.
 */
final class NullProcessTree implements ProcessTree
{
    private string $reason;

    public function __construct(string $reason)
    {
        $this->reason = $reason;
    }

    public function descendants(int $rootPid): array
    {
        return [];
    }

    public function alive(array $pids): array
    {
        return [];
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function getUnavailableReason(): string
    {
        return $this->reason;
    }
}
