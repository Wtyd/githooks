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
        exec('wmic cpu get NumberOfLogicalProcessors ' . Platform::stderrRedirect(), $output, $exitCode);
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
        exec('nproc 2>/dev/null', $output, $exitCode);
        if ($exitCode === 0 && !empty($output)) {
            return (int) $output[0];
        }

        // macOS: sysctl
        $output = [];
        exec('sysctl -n hw.ncpu 2>/dev/null', $output, $exitCode);
        if ($exitCode === 0 && !empty($output)) {
            return (int) $output[0];
        }

        // Fallback: /proc/cpuinfo
        if (is_readable('/proc/cpuinfo')) {
            $content = file_get_contents('/proc/cpuinfo');
            if ($content !== false) {
                $count = substr_count($content, 'processor');
                if ($count > 0) {
                    return $count;
                }
            }
        }

        return 1;
    }

    protected function isWindows(): bool
    {
        return Platform::isWindows();
    }
}
