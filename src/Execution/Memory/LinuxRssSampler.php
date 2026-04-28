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
     */
    private function readVmRssKb(int $pid): ?int
    {
        $path = "/proc/{$pid}/status";
        if (!is_readable($path)) {
            return null;
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }
        if (preg_match('/^VmRSS:\s+(\d+)\s+kB/m', $contents, $matches) !== 1) {
            return null;
        }
        return (int) $matches[1];
    }

    /**
     * Read direct children of a PID via /proc/<PID>/task/<PID>/children
     * (Linux 3.5+). Returns an empty array when the file is unreadable.
     *
     * @return int[]
     */
    private function readChildren(int $pid): array
    {
        $path = "/proc/{$pid}/task/{$pid}/children";
        if (!is_readable($path)) {
            return [];
        }
        $contents = file_get_contents($path);
        if ($contents === false || $contents === '') {
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
}
