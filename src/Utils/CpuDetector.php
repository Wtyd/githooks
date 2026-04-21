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
            return (int) $output[0];
        }

        // macOS: sysctl
        $output = [];
        $this->execCommand('sysctl -n hw.ncpu 2>/dev/null', $output, $exitCode);
        if ($exitCode === 0 && !empty($output)) {
            return (int) $output[0];
        }

        // Fallback: /proc/cpuinfo
        $procCount = $this->readProcCpuinfoCount();
        if ($procCount > 0) {
            return $procCount;
        }

        return 1;
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
