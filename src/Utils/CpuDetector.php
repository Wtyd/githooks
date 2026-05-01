<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Utils;

class CpuDetector
{
    public function detect(): int
    {
        if ($this->isWindows()) {
            return $this->detectWindows();
        }

        return $this->detectUnix();
    }

    protected function detectWindows(): int
    {
        // Environment variable (fastest, most reliable)
        $env = getenv('NUMBER_OF_PROCESSORS');
        if ($env !== false && (int) $env > 0) {
            return (int) $env;
        }

        // wmic fallback (legacy Windows)
        $output = [];
        $exitCode = 0;
        $this->execCommand('wmic cpu get NumberOfLogicalProcessors ' . Platform::stderrRedirect(), $output, $exitCode);
        if ($exitCode === 0) {
            foreach ($output as $line) {
                $trimmed = trim($line);
                if (is_numeric($trimmed) && (int) $trimmed > 0) {
                    return (int) $trimmed;
                }
            }
        }

        return 1;
    }

    protected function detectUnix(): int
    {
        // Linux: nproc
        $output = [];
        $exitCode = 0;
        $this->execCommand('nproc 2>/dev/null', $output, $exitCode);
        if ($exitCode === 0 && !empty($output)) {
            return $this->clampToCgroupLimit((int) $output[0]);
        }

        // macOS: sysctl (no cgroups, return verbatim)
        $output = [];
        $this->execCommand('sysctl -n hw.ncpu 2>/dev/null', $output, $exitCode);
        if ($exitCode === 0 && !empty($output)) {
            return (int) $output[0];
        }

        // Fallback: /proc/cpuinfo (Linux without nproc on PATH)
        $procCount = $this->readProcCpuinfoCount();
        if ($procCount > 0) {
            return $this->clampToCgroupLimit($procCount);
        }

        return 1;
    }

    /**
     * Honour the cgroup CPU quota when this process runs inside a container
     * that limits CPUs (Docker `cpu_count`, Kubernetes pod limits, systemd
     * slice). Without the clamp the CLI sees the host's CPU count and the
     * thread-budget allocator over-reserves, deadlocking FifoAdmission
     * (root cause of BUGs 1/2/3 outside this fix).
     *
     * cgroup v2 (`/sys/fs/cgroup/cpu.max`): "<quota> <period>" or "max <period>".
     * cgroup v1 (`/sys/fs/cgroup/cpu/cpu.cfs_quota_us` + cpu.cfs_period_us):
     *   quota -1 means unlimited.
     *
     * Returns min(detected, ceil(quota / period)). Falls back to detected
     * verbatim when no cgroup limit is in force or the files aren't readable.
     */
    protected function clampToCgroupLimit(int $detected): int
    {
        $limit = $this->readCgroupCpuLimit();
        if ($limit === null || $limit < 1) {
            return $detected;
        }
        return min($detected, $limit);
    }

    /**
     * Read the effective cgroup CPU limit in whole CPUs.
     * Returns null when there is no quota (unlimited) or files are unreadable.
     * Extracted as protected so tests can substitute via a stub subclass.
     */
    protected function readCgroupCpuLimit(): ?int
    {
        $limits = [];

        // cgroup v2 — cpu.max
        $v2 = $this->readFileContents('/sys/fs/cgroup/cpu.max');
        if ($v2 !== null) {
            $trimmed = trim($v2);
            if ($trimmed !== '' && strpos($trimmed, 'max') !== 0) {
                $parts = preg_split('/\s+/', $trimmed);
                if (is_array($parts) && count($parts) >= 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
                    $quota = (int) $parts[0];
                    $period = (int) $parts[1];
                    if ($quota > 0 && $period > 0) {
                        $limits[] = (int) ceil($quota / $period);
                    }
                }
            }
        } else {
            // cgroup v1 — cfs_quota_us / cfs_period_us
            $quotaRaw = $this->readFileContents('/sys/fs/cgroup/cpu/cpu.cfs_quota_us');
            $periodRaw = $this->readFileContents('/sys/fs/cgroup/cpu/cpu.cfs_period_us');
            if ($quotaRaw !== null && $periodRaw !== null) {
                $quota = (int) trim($quotaRaw);
                $period = (int) trim($periodRaw);
                if ($quota > 0 && $period > 0) {
                    $limits[] = (int) ceil($quota / $period);
                }
            }
        }

        // cpuset — count of CPUs the container is pinned to. Same parsing
        // for cgroup v2 (`/sys/fs/cgroup/cpuset.cpus.effective`) and v1
        // (`/sys/fs/cgroup/cpuset/cpuset.cpus`); the effective file wins
        // because it reflects what the kernel actually allows.
        $cpusetRaw = $this->readFileContents('/sys/fs/cgroup/cpuset.cpus.effective')
            ?? $this->readFileContents('/sys/fs/cgroup/cpuset/cpuset.cpus');
        if ($cpusetRaw !== null) {
            $count = $this->countCpusetEntries(trim($cpusetRaw));
            if ($count > 0) {
                $limits[] = $count;
            }
        }

        if ($limits === []) {
            return null;
        }
        return min($limits);
    }

    /**
     * Count distinct CPU IDs in a cpuset list of the form "0-3,5,8-11".
     * Returns 0 for an empty or unparseable string (caller treats as no limit).
     */
    protected function countCpusetEntries(string $cpuset): int
    {
        if ($cpuset === '') {
            return 0;
        }
        $count = 0;
        foreach (explode(',', $cpuset) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }
            if (strpos($segment, '-') !== false) {
                [$lo, $hi] = explode('-', $segment, 2);
                if (is_numeric($lo) && is_numeric($hi) && (int) $hi >= (int) $lo) {
                    $count += ((int) $hi - (int) $lo) + 1;
                }
                continue;
            }
            if (is_numeric($segment)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Read a file and return its contents, or null if unreadable. Protected
     * so tests can stub filesystem responses without touching real /sys.
     */
    protected function readFileContents(string $path): ?string
    {
        if (!is_readable($path)) {
            return null;
        }
        $content = @file_get_contents($path);
        return $content === false ? null : $content;
    }

    /**
     * Count "processor" entries in /proc/cpuinfo. Returns 0 if unreadable.
     * Extracted as a protected method so tests can stub it.
     */
    protected function readProcCpuinfoCount(): int
    {
        if (!is_readable('/proc/cpuinfo')) {
            return 0;
        }
        $content = file_get_contents('/proc/cpuinfo');
        if ($content === false) {
            return 0;
        }
        return substr_count($content, 'processor');
    }

    protected function isWindows(): bool
    {
        return Platform::isWindows();
    }

    /**
     * Thin wrapper over `exec()` so tests can substitute a stub subclass.
     *
     * @param string      $command
     * @param array<int,string> $output
     * @param int         $exitCode
     */
    protected function execCommand(string $command, array &$output, int &$exitCode): void
    {
        exec($command, $output, $exitCode);
    }
}
