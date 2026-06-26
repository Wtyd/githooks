<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output\Inspection;

/**
 * Diagnostic model for `system:info`: detected CPUs and the configured number
 * of processes (null when no usable v3 configuration was found).
 *
 * Owns the single comparison that classifies the relationship between
 * `processes` and `cpus`, so the text renderer and the JSON formatter cannot
 * diverge on the boundary `processes === cpus`.
 */
final class SystemInfo
{
    public const STATUS_NO_CONFIG = 'no-config';
    public const STATUS_WARNING = 'warning';
    public const STATUS_TIP = 'tip';
    public const STATUS_OK = 'ok';

    private int $cpus;

    private ?int $processes;

    public function __construct(int $cpus, ?int $processes)
    {
        $this->cpus = $cpus;
        $this->processes = $processes;
    }

    public function getCpus(): int
    {
        return $this->cpus;
    }

    public function getProcesses(): ?int
    {
        return $this->processes;
    }

    public function status(): string
    {
        if ($this->processes === null) {
            return self::STATUS_NO_CONFIG;
        }

        if ($this->processes > $this->cpus) {
            return self::STATUS_WARNING;
        }

        if ($this->processes === 1) {
            return self::STATUS_TIP;
        }

        return self::STATUS_OK;
    }

    /**
     * Over-subscription warning message, or null when processes is within the
     * available CPUs (or no configuration was found).
     */
    public function warning(): ?string
    {
        if ($this->status() !== self::STATUS_WARNING) {
            return null;
        }

        return "'processes' ($this->processes) exceeds available CPUs ($this->cpus). This may saturate the machine.";
    }
}
