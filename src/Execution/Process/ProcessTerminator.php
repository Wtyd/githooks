<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution\Process;

use Symfony\Component\Process\Process;

/**
 * Kills the jobs a ProcessPool has in flight — the whole process tree of
 * each one, not just the `sh -c` wrapper that Process::stop() reaches.
 *
 * Symfony's Process::fromShellCommandLine() makes getPid() the PID of the
 * shell proc_open spawned; stop() signals that PID and nothing else, so the
 * analyzer underneath (php phpstan.phar, artisan test --parallel and its
 * workers…) was reparented to PID 1 and kept running (BUG-35). The order
 * here is the part that matters:
 *
 *   1. Enumerate every tree BEFORE sending the first signal — killing a
 *      parent reparents its children and the reference is lost.
 *   2. SIGTERM leaf → root (descendants deepest first, the wrapper last).
 *   3. Wait up to $graceMs for everything to exit; zombies do not count.
 *   4. SIGKILL whatever is still alive.
 *   5. Process::stop(0) on each wrapper so Symfony reaps it and closes pipes.
 *
 * Without ext-posix, or on a platform whose tree cannot be walked, it
 * degrades to step 5 alone (the previous behaviour) with a stderr warning —
 * never a fatal. Signals are numeric because the SIG* constants come from
 * ext-pcntl, which is not guaranteed on CLI builds either.
 */
class ProcessTerminator
{
    public const DEFAULT_GRACE_MS = 5000;

    private const SIGTERM = 15;

    private const SIGKILL = 9;

    private const POLL_INTERVAL_US = 10000;

    private ProcessTree $tree;

    private int $graceMs;

    private bool $canSignal;

    public function __construct(ProcessTree $tree, int $graceMs = self::DEFAULT_GRACE_MS, ?bool $canSignal = null)
    {
        $this->tree = $tree;
        $this->graceMs = max(0, $graceMs);
        $this->canSignal = $canSignal ?? function_exists('posix_kill');
    }

    /**
     * @param Process[] $processes Running processes (one per job in flight).
     */
    public function terminate(array $processes): void
    {
        if ($processes === []) {
            return;
        }

        if ($this->canSignal && $this->tree->isAvailable()) {
            $this->terminateTrees($processes);
        } else {
            $this->emitWarning(
                '⚠ Process tree kill unavailable (' . $this->unavailableReason()
                . '): falling back to Process::stop() on each job'
            );
        }

        // Reap through Symfony: collects the wrapper's exit code and closes
        // its pipes. In the tree path the wrapper is already dead by now.
        foreach ($processes as $process) {
            $process->stop(0);
        }
    }

    /**
     * @param Process[] $processes
     */
    private function terminateTrees(array $processes): void
    {
        $victims = [];
        foreach ($processes as $process) {
            $rootPid = $process->getPid();
            if ($rootPid === null) {
                continue;
            }
            // Leaves first: reverse the parents-before-children BFS order.
            foreach (array_reverse($this->tree->descendants($rootPid)) as $pid) {
                $victims[] = $pid;
            }
            $victims[] = $rootPid;
        }
        $victims = $this->signalable($victims);
        if ($victims === []) {
            return;
        }

        foreach ($victims as $pid) {
            $this->sendSignal($pid, self::SIGTERM);
        }

        foreach ($this->waitForExit($victims) as $pid) {
            $this->sendSignal($pid, self::SIGKILL);
        }
    }

    /**
     * Poll the tree until every PID is gone (or a zombie) or the grace
     * period runs out. Returns the survivors.
     *
     * @param int[] $pids
     * @return int[]
     */
    private function waitForExit(array $pids): array
    {
        $deadline = $this->now() + $this->graceMs / 1000;
        $alive = $this->tree->alive($pids);
        while ($alive !== [] && $this->now() < $deadline) {
            $this->pause();
            $alive = $this->tree->alive($pids);
        }
        return $alive;
    }

    /**
     * Deduplicate and drop the PIDs that must never be signalled: our own
     * process and PID 1 (or anything below), whatever the tree reported.
     *
     * @param int[] $pids
     * @return int[]
     */
    private function signalable(array $pids): array
    {
        $self = getmypid();
        $unique = [];
        foreach ($pids as $pid) {
            if ($pid <= 1 || $pid === $self || isset($unique[$pid])) {
                continue;
            }
            $unique[$pid] = true;
        }
        return array_keys($unique);
    }

    private function unavailableReason(): string
    {
        return $this->canSignal ? $this->tree->getUnavailableReason() : 'ext-posix not loaded';
    }

    /**
     * Seam for tests. posix_kill() returns false (no warning) when the PID is
     * already gone, which is expected mid-kill and safe to ignore.
     */
    protected function sendSignal(int $pid, int $signal): void
    {
        posix_kill($pid, $signal);
    }

    /** Seam for tests: virtual clock. */
    protected function now(): float
    {
        return microtime(true);
    }

    /** Seam for tests: no real sleep. */
    protected function pause(): void
    {
        usleep(self::POLL_INTERVAL_US);
    }

    /** Seam for tests: production writes to STDERR. */
    protected function emitWarning(string $message): void
    {
        fwrite(STDERR, $message . PHP_EOL);
    }
}
