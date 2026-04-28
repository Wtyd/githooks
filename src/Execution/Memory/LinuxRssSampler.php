<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution\Memory;

/**
 * Linux RSS sampler backed by /proc/<PID>/status. Reads VmRSS (kB) for each
 * PID and converts to MB with integer division. Errors per-PID are silently
 * ignored: a process can vanish between getRunningPids() and the actual read.
 */
final class LinuxRssSampler implements MemorySampler
{
    public function sample(array $jobNameToPid): array
    {
        $result = [];

        foreach ($jobNameToPid as $jobName => $pid) {
            $rssMb = $this->readRssMb($pid);
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
     * Read VmRSS from /proc/<PID>/status and convert kB → MB.
     * Returns null when the read fails (process gone, no /proc entry,
     * VmRSS line missing).
     */
    private function readRssMb(int $pid): ?int
    {
        if ($pid <= 0) {
            return null;
        }

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

        return (int) ((int) $matches[1] / 1024);
    }
}
