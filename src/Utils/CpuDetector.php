<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Utils;

/**
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Coheres CPU detection across
 *   Windows/Unix/macOS plus cgroup v1+v2 quota and cpuset limits; splitting
 *   into multiple classes would only spread the same concern.
 */
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
        $quotaLimit = $this->readCgroupQuotaLimit();
        if ($quotaLimit !== null) {
            $limits[] = $quotaLimit;
        }
        $cpusetLimit = $this->readCpusetLimit();
        if ($cpusetLimit !== null) {
            $limits[] = $cpusetLimit;
        }
        return $limits === [] ? null : min($limits);
    }

    /**
     * cgroup v2 (`cpu.max` = "<quota> <period>" or "max …") with v1 fallback
     * (`cfs_quota_us` + `cfs_period_us`, -1 = unlimited). Returns ceil(quota/period)
     * in whole CPUs, or null when there is no quota in force.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Two parsing paths (v2/v1)
     *   each guarded against missing files, garbage and signed values.
     */
    protected function readCgroupQuotaLimit(): ?int
    {
        $cpuMax = $this->readFileContents('/sys/fs/cgroup/cpu.max');
        if ($cpuMax !== null) {
            $trimmed = trim($cpuMax);
            if ($trimmed === '' || strpos($trimmed, 'max') === 0) {
                return null;
            }
            $parts = preg_split('/\s+/', $trimmed) ?: [];
            if (count($parts) < 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
                return null;
            }
            return $this->divideQuota((int) $parts[0], (int) $parts[1]);
        }

        $quotaRaw = $this->readFileContents('/sys/fs/cgroup/cpu/cpu.cfs_quota_us');
        $periodRaw = $this->readFileContents('/sys/fs/cgroup/cpu/cpu.cfs_period_us');
        if ($quotaRaw === null || $periodRaw === null) {
            return null;
        }
        return $this->divideQuota((int) trim($quotaRaw), (int) trim($periodRaw));
    }

    /**
     * cpuset effective count (cgroup v2 `cpuset.cpus.effective`, v1 fallback).
     * Returns the number of CPUs the container is pinned to, or null when
     * neither file is available or the list parses as empty.
     */
    protected function readCpusetLimit(): ?int
    {
        $cpusetRaw = $this->readFileContents('/sys/fs/cgroup/cpuset.cpus.effective')
            ?? $this->readFileContents('/sys/fs/cgroup/cpuset/cpuset.cpus');
        if ($cpusetRaw === null) {
            return null;
        }
        $count = $this->countCpusetEntries(trim($cpusetRaw));
        return $count > 0 ? $count : null;
    }

    private function divideQuota(int $quota, int $period): ?int
    {
        if ($quota <= 0 || $period <= 0) {
            return null;
        }
        return (int) ceil($quota / $period);
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
            $count += $this->countCpusetSegment(trim($segment));
        }
        return $count;
    }

    private function countCpusetSegment(string $segment): int
    {
        if ($segment === '') {
            return 0;
        }
        if (strpos($segment, '-') !== false) {
            [$rangeStart, $rangeEnd] = explode('-', $segment, 2);
            if (is_numeric($rangeStart) && is_numeric($rangeEnd) && (int) $rangeEnd >= (int) $rangeStart) {
                return ((int) $rangeEnd - (int) $rangeStart) + 1;
            }
            return 0;
        }
        return is_numeric($segment) ? 1 : 0;
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
        try {
            $content = file_get_contents($path);
        } catch (\Throwable $error) {
            // /sys/fs/cgroup files can vanish or flip readability between the
            // is_readable() check and the read; a strict error handler would
            // upgrade the warning to an exception, which we swallow here.
            return null;
        }
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
