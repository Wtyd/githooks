<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution\Memory;

/**
 * Linux RSS sampler backed by /proc/<PID>/status. Sums VmRSS across the
 * entire process tree rooted at the given PID, so values reflect the
 * memory of the actual analyzer (php phpstan.phar, php phpunit, ...) and
 * not just the shell wrapper Symfony Process spawns. Errors per-PID are
 * silently ignored — processes can vanish between the children listing
 * and the status read.
 *
 * Tree walk uses /proc/<PID>/task/<PID>/children (Linux 3.5+), which is
 * O(descendants) per sample — much cheaper than scanning /proc.
 */
final class LinuxRssSampler implements MemorySampler
{
    private const MAX_TREE_DEPTH = 16;

    public function sample(array $jobNameToPid): array
    {
        $result = [];

        foreach ($jobNameToPid as $jobName => $pid) {
            $rssMb = $this->readTreeRssMb($pid);
            if ($rssMb !== null) {
                $result[$jobName] = $rssMb;
            }
        }

        return $result;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getUnavailableReason(): string
    {
        return '';
    }

    /**
     * Sum VmRSS across the process tree rooted at $rootPid. Returns null
     * when even the root cannot be read (process gone before sampling);
     * partial reads of descendants are tolerated silently.
     */
    private function readTreeRssMb(int $rootPid): ?int
    {
        if ($rootPid <= 0) {
            return null;
        }

        $rootKb = $this->readVmRssKb($rootPid);
        if ($rootKb === null) {
            return null;
        }

        $totalKb = $rootKb;
        $queue = [[$rootPid, 0]];
        $visited = [$rootPid => true];

        while (!empty($queue)) {
            [$pid, $depth] = array_shift($queue);
            if ($depth >= self::MAX_TREE_DEPTH) {
                continue;
            }
            foreach ($this->readChildren($pid) as $childPid) {
                if (isset($visited[$childPid])) {
                    continue;
                }
                $visited[$childPid] = true;
                $childKb = $this->readVmRssKb($childPid);
                if ($childKb !== null) {
                    $totalKb += $childKb;
                }
                $queue[] = [$childPid, $depth + 1];
            }
        }

        return (int) ($totalKb / 1024);
    }

    /**
     * Read VmRSS in kB for a single PID, or null when unreadable.
     *
     * Both `is_readable()` and `file_get_contents()` are racy against /proc
     * pseudo-files: a child can vanish between the check and the read. The
     * `@` suppresses the warning when the file disappears, and the
     * try/catch captures cases where Laravel-Zero's strict error handler
     * still upgrades it to ErrorException. A vanished PID is normal during
     * a sample tick and must not crash the executor.
     */
    private function readVmRssKb(int $pid): ?int
    {
        $contents = self::safeRead("/proc/{$pid}/status");
        if ($contents === null) {
            return null;
        }
        if (preg_match('/^VmRSS:\s+(\d+)\s+kB/m', $contents, $matches) !== 1) {
            return null;
        }
        return (int) $matches[1];
    }

    /**
     * Read direct children of a PID via /proc/<PID>/task/<PID>/children
     * (Linux 3.5+). Returns an empty array when the file is unreadable or
     * the process has gone.
     *
     * @return int[]
     */
    private function readChildren(int $pid): array
    {
        $contents = self::safeRead("/proc/{$pid}/task/{$pid}/children");
        if ($contents === null || $contents === '') {
            return [];
        }
        $pids = [];
        foreach (preg_split('/\s+/', trim($contents)) ?: [] as $token) {
            if ($token === '') {
                continue;
            }
            if (ctype_digit($token)) {
                $pids[] = (int) $token;
            }
        }
        return $pids;
    }

    /**
     * Best-effort read of a /proc pseudo-file. Returns null on any failure
     * (file gone, permission denied, transient I/O error). The error
     * control operator is intentional and necessary here:
     *
     *  - With `@`, error_reporting() drops to 0 inside the expression and
     *    the standard Symfony/Laravel-Zero error handler short-circuits
     *    its warning-to-ErrorException upgrade, so the read fails quietly
     *    by returning false. This is the hot path each second under
     *    --threads=10 mutation testing — keeping it allocation-free and
     *    throw-free matters.
     *  - The try/catch is the safety net for the rarer case of a strict
     *    handler that ignores error_reporting() and throws anyway. We
     *    catch \Throwable so any vendor-specific exception type gets
     *    swallowed too.
     *
     * PHPMD's ErrorControlOperator rule is correct in general but not for
     * race-prone reads against procfs that we explicitly want to swallow.
     *
     * @SuppressWarnings(PHPMD.ErrorControlOperator)
     */
    private static function safeRead(string $path): ?string
    {
        try {
            $contents = @file_get_contents($path);
        } catch (\Throwable $e) {
            return null;
        }
        return $contents === false ? null : $contents;
    }
}
