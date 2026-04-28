<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution\Memory;

/**
 * Reads the resident set size (RSS) of a set of running processes once.
 * Implementations are platform-specific; the factory selects the right one
 * for the current OS and returns a NullRssSampler when no implementation
 * is available (graceful degradation, REQ-038).
 *
 * The interface is intentionally pull-style: the FlowExecutor decides when
 * to sample (every 1s while jobs are in flight, REQ-023) and passes the
 * live PID set. Implementations do NOT spawn threads; they answer in-band.
 */
interface MemorySampler
{
    /**
     * Read the current RSS for each PID. Jobs whose PID cannot be read
     * (process gone, /proc entry vanished, ps invocation failed) are
     * silently omitted from the result array (REQ-024).
     *
     * @param array<string, int> $jobNameToPid Map of job name → live PID.
     * @return array<string, int> Map of job name → current RSS in MB.
     */
    public function sample(array $jobNameToPid): array;

    /**
     * Whether the sampler can produce non-empty results on this platform.
     * Drives the graceful-degradation warning and the disabling of
     * memory thresholds when false (CON-002).
     */
    public function isAvailable(): bool;

    /**
     * Human-readable reason when not available. Empty string when
     * isAvailable() is true.
     */
    public function getUnavailableReason(): string;
}
