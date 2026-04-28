<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution\Memory;

/**
 * Selects the appropriate RSS sampler for the current platform. Returns a
 * LinuxRssSampler when /proc is mounted on a Linux host; a NullRssSampler
 * with a human-readable reason on every other platform (macOS, Windows,
 * Linux without /proc).
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
        if ($this->osFamily !== 'Linux') {
            $reason = sprintf(
                'RSS sampling not available on %s (only Linux /proc is supported in v3.3)',
                $this->osFamily
            );
            return new NullRssSampler($reason);
        }

        if (!$this->hasProc) {
            return new NullRssSampler('RSS sampling not available: /proc not mounted on this host');
        }

        return new LinuxRssSampler();
    }
}
