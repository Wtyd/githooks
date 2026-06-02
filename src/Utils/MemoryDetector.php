<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Utils;

/**
 * Reads **system** memory (the runner's RAM), not the PHP process memory.
 * Used by the diagnostics block (FEAT-14) to record how much memory was free
 * when the flow started — the key signal for postmortems of CI incidents where
 * memory pressure (not CPU) froze the runner.
 *
 * Platform support mirrors {@see CpuDetector}:
 *   - Linux: `/proc/meminfo` (MemTotal/MemAvailable), clamped by the cgroup
 *     limit (`memory.max` / `memory.current`) when running inside a container.
 *   - macOS: `sysctl hw.memsize` (total) + `vm_stat` (available), best-effort.
 *   - Windows: not read — both fields are null (the contract serialises null).
 *
 * Protected seams (`readFileContents`, `execCommand`, `isWindows`, `isMacOS`)
 * are overridable so tests stub the filesystem/commands per platform.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class MemoryDetector
{
    /**
     * @return array{availableMb: ?int, totalMb: ?int} null fields where the
     *         platform cannot report the value (never throws).
     */
    public function detect(): array
    {
        if ($this->isWindows()) {
            return ['availableMb' => null, 'totalMb' => null];
        }
        if ($this->isMacOS()) {
            return $this->detectMacOS();
        }
        return $this->detectLinux();
    }

    /**
     * @return array{availableMb: ?int, totalMb: ?int}
     */
    protected function detectLinux(): array
    {
        $meminfo = $this->readFileContents('/proc/meminfo');
        if ($meminfo === null) {
            return ['availableMb' => null, 'totalMb' => null];
        }
        $totalMb = $this->kbLineToMb($meminfo, 'MemTotal');
        $availableMb = $this->kbLineToMb($meminfo, 'MemAvailable');

        // Inside a container the cgroup limit is tighter than the host's RAM.
        $cgroupTotalMb = $this->readCgroupMemoryMaxMb();
        if ($cgroupTotalMb !== null) {
            $cgroupUsedMb = $this->readCgroupMemoryCurrentMb();
            $totalMb = $totalMb === null ? $cgroupTotalMb : min($totalMb, $cgroupTotalMb);
            if ($cgroupUsedMb !== null) {
                $cgroupAvailableMb = max(0, $cgroupTotalMb - $cgroupUsedMb);
                $availableMb = $availableMb === null
                    ? $cgroupAvailableMb
                    : min($availableMb, $cgroupAvailableMb);
            }
        }

        return ['availableMb' => $availableMb, 'totalMb' => $totalMb];
    }

    /**
     * Best-effort macOS reading: `hw.memsize` (bytes) for total, `vm_stat`
     * (free + inactive + speculative pages × page size) for available.
     *
     * @return array{availableMb: ?int, totalMb: ?int}
     */
    protected function detectMacOS(): array
    {
        $totalMb = null;
        $output = [];
        $exit = 0;
        $this->execCommand('sysctl -n hw.memsize 2>/dev/null', $output, $exit);
        if ($exit === 0 && isset($output[0]) && is_numeric(trim($output[0]))) {
            $totalMb = (int) ((int) trim($output[0]) / 1024 / 1024);
        }

        return ['availableMb' => $this->readMacOSAvailableMb(), 'totalMb' => $totalMb];
    }

    protected function readMacOSAvailableMb(): ?int
    {
        $output = [];
        $exit = 0;
        $this->execCommand('vm_stat 2>/dev/null', $output, $exit);
        if ($exit !== 0 || $output === []) {
            return null;
        }
        $text = implode("\n", $output);
        $pageSize = $this->matchInt($text, '/page size of (\d+) bytes/');
        if ($pageSize === null) {
            return null;
        }
        $free = $this->matchInt($text, '/Pages free:\s+(\d+)\./');
        $inactive = $this->matchInt($text, '/Pages inactive:\s+(\d+)\./');
        $speculative = $this->matchInt($text, '/Pages speculative:\s+(\d+)\./');
        if ($free === null && $inactive === null) {
            return null;
        }
        $pages = ($free ?? 0) + ($inactive ?? 0) + ($speculative ?? 0);
        return (int) ($pages * $pageSize / 1024 / 1024);
    }

    /**
     * cgroup v2 `memory.max` (v1 fallback `memory.limit_in_bytes`) in MB.
     * Null when unlimited ("max"), unreadable, or absurdly large (host-sized
     * sentinel like PHP_INT_MAX that some kernels use for "no limit").
     */
    protected function readCgroupMemoryMaxMb(): ?int
    {
        $raw = $this->readFileContents('/sys/fs/cgroup/memory.max')
            ?? $this->readFileContents('/sys/fs/cgroup/memory/memory.limit_in_bytes');
        if ($raw === null) {
            return null;
        }
        $trimmed = trim($raw);
        if ($trimmed === '' || $trimmed === 'max' || !is_numeric($trimmed)) {
            return null;
        }
        $bytes = (int) $trimmed;
        // Kernels report "no limit" as a near-INT_MAX sentinel (e.g. 0x7FFFFFFFFFFFF000).
        if ($bytes <= 0 || $bytes >= PHP_INT_MAX - 4096) {
            return null;
        }
        return (int) ($bytes / 1024 / 1024);
    }

    protected function readCgroupMemoryCurrentMb(): ?int
    {
        $raw = $this->readFileContents('/sys/fs/cgroup/memory.current')
            ?? $this->readFileContents('/sys/fs/cgroup/memory/memory.usage_in_bytes');
        if ($raw === null || !is_numeric(trim($raw))) {
            return null;
        }
        return (int) ((int) trim($raw) / 1024 / 1024);
    }

    /**
     * Extract a "Label: <N> kB" value from /proc/meminfo and return it in MB.
     */
    private function kbLineToMb(string $meminfo, string $label): ?int
    {
        $kiloBytes = $this->matchInt($meminfo, '/^' . preg_quote($label, '/') . ':\s+(\d+)\s*kB/m');
        return $kiloBytes === null ? null : (int) ($kiloBytes / 1024);
    }

    private function matchInt(string $haystack, string $pattern): ?int
    {
        if (preg_match($pattern, $haystack, $matches) === 1) {
            return (int) $matches[1];
        }
        return null;
    }

    protected function isWindows(): bool
    {
        return Platform::isWindows();
    }

    protected function isMacOS(): bool
    {
        return strtoupper(substr(PHP_OS, 0, 6)) === 'DARWIN';
    }

    /**
     * Read a file and return its contents, or null if unreadable. Protected so
     * tests can stub filesystem responses without touching real /proc or /sys.
     */
    protected function readFileContents(string $path): ?string
    {
        if (!is_readable($path)) {
            return null;
        }
        try {
            $content = file_get_contents($path);
        } catch (\Throwable $error) {
            return null;
        }
        return $content === false ? null : $content;
    }

    /**
     * Thin wrapper over `exec()` so tests can substitute a stub subclass.
     *
     * @param array<int,string> $output
     */
    protected function execCommand(string $command, array &$output, int &$exitCode): void
    {
        exec($command, $output, $exitCode);
    }
}
