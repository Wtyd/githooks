<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution\Process;

/**
 * macOS process tree read through `ps`. Darwin has no /proc, so the tree
 * is rebuilt from one full listing (`ps -o pid=,ppid= -ax`) per walk and
 * liveness comes from `ps -o pid=,stat= -p <pids>` — one proc_open per
 * call regardless of how many PIDs are involved, matching MacOsRssSampler.
 */
class MacOsProcessTree implements ProcessTree
{
    public function descendants(int $rootPid): array
    {
        if ($rootPid <= 0) {
            return [];
        }

        $listing = $this->runProcessListing('ps -o pid=,ppid= -ax');
        if ($listing === null) {
            return [];
        }

        $children = $this->parseChildren($listing);
        return ProcessTreeWalk::descendants($rootPid, function (int $pid) use ($children): array {
            return $children[$pid] ?? [];
        });
    }

    public function alive(array $pids): array
    {
        if ($pids === []) {
            return [];
        }

        $listing = $this->runProcessListing('ps -o pid=,stat= -p ' . implode(',', $pids));
        if ($listing === null) {
            return [];
        }

        $states = $this->parseStates($listing);
        $alive = [];
        foreach ($pids as $pid) {
            $state = $states[$pid] ?? null;
            if ($state !== null && $state[0] !== 'Z') {
                $alive[] = $pid;
            }
        }
        return $alive;
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
     * Run a `ps` command and return its raw stdout, or null when ps is
     * unavailable or printed nothing. The exit code is deliberately
     * ignored: `ps -p` exits non-zero when some of the requested PIDs are
     * already gone, and the lines it did print are exactly what we need.
     *
     * Protected seam: tests feed synthetic listings keyed by command.
     */
    protected function runProcessListing(string $command): ?string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $stdout === '' ? null : $stdout;
    }

    /**
     * `pid ppid` lines → PPID → [children] adjacency list. Lines that do
     * not match the expected shape are silently dropped.
     *
     * @return array<int, int[]>
     */
    private function parseChildren(string $listing): array
    {
        $children = [];
        foreach (explode("\n", $listing) as $line) {
            if (preg_match('/^\s*(\d+)\s+(\d+)\s*$/', $line, $matches) !== 1) {
                continue;
            }
            $children[(int) $matches[2]][] = (int) $matches[1];
        }
        return $children;
    }

    /**
     * `pid stat` lines → PID → state string (`S+`, `R`, `Z`, …).
     *
     * @return array<int, string>
     */
    private function parseStates(string $listing): array
    {
        $states = [];
        foreach (explode("\n", $listing) as $line) {
            if (preg_match('/^\s*(\d+)\s+(\S+)/', $line, $matches) !== 1) {
                continue;
            }
            $states[(int) $matches[1]] = $matches[2];
        }
        return $states;
    }
}
