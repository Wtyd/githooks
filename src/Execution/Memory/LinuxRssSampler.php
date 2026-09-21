<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution\Memory;

use Wtyd\GitHooks\Execution\Concerns\ReadsProcFiles;
use Wtyd\GitHooks\Execution\Process\LinuxProcessTree;

/**
 * Linux RSS sampler backed by /proc/<PID>/status. Sums VmRSS across the
 * entire process tree rooted at the given PID, so values reflect the
 * memory of the actual analyzer (php phpstan.phar, php phpunit, ...) and
 * not just the shell wrapper Symfony Process spawns. Errors per-PID are
 * silently ignored — processes can vanish between the children listing
 * and the status read.
 *
 * The tree walk is LinuxProcessTree's (/proc/<PID>/task/<PID>/children,
 * Linux 3.5+) — the same one ProcessTerminator uses to kill a job's
 * descendants, so what gets measured is exactly what gets killed.
 */
class LinuxRssSampler implements MemorySampler
{
    use ReadsProcFiles;

    private LinuxProcessTree $tree;

    public function __construct(?LinuxProcessTree $tree = null)
    {
        $this->tree = $tree ?? new LinuxProcessTree();
    }

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
        foreach ($this->tree->descendants($rootPid) as $childPid) {
            $childKb = $this->readVmRssKb($childPid);
            if ($childKb !== null) {
                $totalKb += $childKb;
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
        $contents = $this->readProcFile("/proc/{$pid}/status");
        if ($contents === null) {
            return null;
        }
        if (preg_match('/^VmRSS:\s+(\d+)\s+kB/m', $contents, $matches) !== 1) {
            return null;
        }
        return (int) $matches[1];
    }
}
