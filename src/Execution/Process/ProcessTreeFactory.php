<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution\Process;

/**
 * Selects the process tree for the current platform: Linux (/proc), macOS
 * (ps) and a NullProcessTree with a human-readable reason everywhere else.
 * Same shape as MemorySamplerFactory; the OS detection is overridable via
 * constructor arguments to keep the class testable without containers.
 */
final class ProcessTreeFactory
{
    private string $osFamily;

    private bool $hasProc;

    public function __construct(?string $osFamily = null, ?bool $hasProc = null)
    {
        $this->osFamily = $osFamily ?? PHP_OS_FAMILY;
        $this->hasProc = $hasProc ?? is_dir('/proc');
    }

    public function create(): ProcessTree
    {
        if ($this->osFamily === 'Linux') {
            return $this->hasProc
                ? new LinuxProcessTree()
                : new NullProcessTree('process tree not available: /proc not mounted');
        }

        if ($this->osFamily === 'Darwin') {
            return new MacOsProcessTree();
        }

        return new NullProcessTree('process tree not available on ' . $this->osFamily);
    }
}
