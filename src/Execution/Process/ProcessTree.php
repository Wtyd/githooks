<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution\Process;

/**
 * Read-only view of the OS process tree. Implementations are
 * platform-specific; ProcessTreeFactory selects the right one and returns
 * a NullProcessTree when the platform offers no way to walk the tree
 * (graceful degradation, same contract as MemorySampler).
 *
 * Two consumers: the RSS samplers sum memory across a job's descendants,
 * and ProcessTerminator kills them — Symfony's Process::stop() only reaches
 * the `sh -c` wrapper it spawned, so without the tree the real analyzer and
 * its workers survive every abort (BUG-35).
 */
interface ProcessTree
{
    /**
     * Every descendant of $rootPid, parents before children (BFS order),
     * root excluded. Empty when the root is gone, the PID is invalid or the
     * tree cannot be read on this platform.
     *
     * @return int[]
     */
    public function descendants(int $rootPid): array;

    /**
     * The subset of $pids that is still alive AND not a zombie, in the
     * given order. A zombie holds no resources and vanishes as soon as its
     * parent reaps it, so it must not count as a survivor — `posix_kill($pid, 0)`
     * would say it is alive, which is the trap this method avoids.
     *
     * @param int[] $pids
     * @return int[]
     */
    public function alive(array $pids): array;

    /**
     * Whether the tree can be walked on this platform. False drives the
     * fallback to Process::stop() plus a one-time stderr warning.
     */
    public function isAvailable(): bool;

    /**
     * Human-readable reason when not available. Empty string when
     * isAvailable() is true.
     */
    public function getUnavailableReason(): string;
}
