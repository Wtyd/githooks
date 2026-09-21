<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution\Memory;

use Wtyd\GitHooks\Execution\Process\ProcessTreeWalk;

/**
 * macOS RSS sampler. Reads the entire process listing once per sample with
 * `ps -o pid=,ppid=,rss= -ax` (RSS in kB), then walks the tree rooted at
 * each requested PID and sums the descendants' RSS — matching the
 * Linux behaviour.
 *
 * One `proc_open` per sample regardless of how many jobs are in flight
 * (CON-003). The parser is split out and exposed as a protected method
 * so tests can drive it with synthetic fixtures without invoking ps.
 */
class MacOsRssSampler implements MemorySampler
{
    public function sample(array $jobNameToPid): array
    {
        if (empty($jobNameToPid)) {
            return [];
        }

        $listing = $this->runProcessListing();
        if ($listing === null) {
            return [];
        }

        return $this->resolveTreeRss($jobNameToPid, $listing);
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
     * Run `ps` and return its raw stdout, or null when ps is unavailable
     * or returned no output (treated as a soft failure — the sample is
     * skipped, the next tick retries).
     */
    protected function runProcessListing(): ?string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open('ps -o pid=,ppid=,rss= -ax', $descriptors, $pipes);
        if (!is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        if ($exit !== 0 || $stdout === '') {
            return null;
        }
        return $stdout;
    }

    /**
     * Build the in-memory process tree from a `ps` listing and sum
     * descendant RSS for each requested root PID.
     *
     * @param array<string, int> $jobNameToPid
     * @return array<string, int>
     */
    private function resolveTreeRss(array $jobNameToPid, string $listing): array
    {
        ['procs' => $procs, 'children' => $children] = $this->parseListing($listing);

        $result = [];
        foreach ($jobNameToPid as $name => $rootPid) {
            if (!isset($procs[$rootPid])) {
                continue;
            }
            $result[$name] = (int) ($this->sumTreeKb($rootPid, $procs, $children) / 1024);
        }
        return $result;
    }

    /**
     * Parse the raw `ps` output into a PID→info map and a PPID→[children]
     * adjacency list. Lines that do not match the expected shape are
     * silently dropped; ps is well-formed in practice.
     *
     * @return array{procs: array<int, array{ppid: int, rss: int}>, children: array<int, int[]>}
     */
    protected function parseListing(string $listing): array
    {
        $procs = [];
        $children = [];

        foreach (explode("\n", $listing) as $line) {
            if (preg_match('/^\s*(\d+)\s+(\d+)\s+(\d+)\s*$/', $line, $matches) !== 1) {
                continue;
            }
            $pid = (int) $matches[1];
            $ppid = (int) $matches[2];
            $rss = (int) $matches[3];

            $procs[$pid] = ['ppid' => $ppid, 'rss' => $rss];
            $children[$ppid][] = $pid;
        }

        return ['procs' => $procs, 'children' => $children];
    }

    /**
     * Sum of VmRSS-equivalent kB across the subtree rooted at $rootPid,
     * walked with the shared ProcessTreeWalk (same depth cap and cycle
     * guard as the Linux sampler and the process-tree kill).
     *
     * @param array<int, array{ppid: int, rss: int}> $procs
     * @param array<int, int[]>                       $children
     */
    private function sumTreeKb(int $rootPid, array $procs, array $children): int
    {
        $totalKb = $procs[$rootPid]['rss'];
        $descendants = ProcessTreeWalk::descendants($rootPid, function (int $pid) use ($children): array {
            return $children[$pid] ?? [];
        });
        foreach ($descendants as $childPid) {
            if (isset($procs[$childPid])) {
                $totalKb += $procs[$childPid]['rss'];
            }
        }
        return $totalKb;
    }
}
