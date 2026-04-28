<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution\Memory;

/**
 * Selects the appropriate RSS sampler for the current platform: Linux
 * (/proc), macOS (ps tree walk) and a NullRssSampler with a human-readable
 * reason on every other platform.
 *
 * The OS detection is overridable via constructor arguments to keep the
 * class testable without spinning up containers.
 */
final class MemorySamplerFactory
{
    private string $osFamily;

    private bool $hasProc;

    public function __construct(?string $osFamily = null, ?bool $hasProc = null)
    {
        $this->osFamily = $osFamily ?? PHP_OS_FAMILY;
        $this->hasProc = $hasProc ?? is_dir('/proc');
    }

    public function create(): MemorySampler
    {
        if ($this->osFamily === 'Linux') {
            return $this->hasProc
                ? new LinuxRssSampler()
                : new NullRssSampler('RSS sampling not available: /proc not mounted');
        }

        if ($this->osFamily === 'Darwin') {
            return new MacOsRssSampler();
        }

        return new NullRssSampler('RSS sampling not available on ' . $this->osFamily);
    }
}
