<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output\Diagnostics;

use Jean85\PrettyVersions;
use Throwable;
use Wtyd\GitHooks\Execution\Diagnostics;
use Wtyd\GitHooks\Output\CI\CIEnvironment;
use Wtyd\GitHooks\Utils\CpuDetector;
use Wtyd\GitHooks\Utils\IsoTimestamp;
use Wtyd\GitHooks\Utils\MemoryDetector;

/**
 * Gathers the runner snapshot for the diagnostics block (FEAT-14) into an
 * immutable {@see Diagnostics} VO, and stamps ISO-8601 timestamps for the
 * flow span.
 *
 * Detectors are injected (so tests pass stubs); the remaining sources (version,
 * platform, CI, load avg, wall clock) are protected seams overridable in a stub
 * subclass — the same testing convention as {@see CpuDetector}. Capture cost is
 * sub-millisecond.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class DiagnosticsCollector
{
    private CpuDetector $cpu;

    private MemoryDetector $memory;

    public function __construct(?CpuDetector $cpu = null, ?MemoryDetector $memory = null)
    {
        $this->cpu = $cpu ?? new CpuDetector();
        $this->memory = $memory ?? new MemoryDetector();
    }

    public function collect(): Diagnostics
    {
        $mem = $this->memory->detect();
        $load = $this->loadAverage();

        return new Diagnostics(
            $this->version(),
            $this->platform(),
            $this->detectCi(),
            $this->cpu->detect(),
            $this->cpu->cgroupLimit(),
            $mem['availableMb'],
            $mem['totalMb'],
            $load[0] ?? null,
            $load[1] ?? null,
            $load[2] ?? null
        );
    }

    /** Current wall-clock as an ISO-8601 timestamp with millisecond precision. */
    public function now(): string
    {
        return IsoTimestamp::fromMicrotime($this->microtime());
    }

    protected function microtime(): float
    {
        return microtime(true);
    }

    protected function version(): string
    {
        try {
            return PrettyVersions::getRootPackageVersion()->getPrettyVersion();
        } catch (Throwable $e) {
            return 'unknown';
        }
    }

    /** Short platform token: 'linux', 'darwin', 'windows', etc. */
    protected function platform(): string
    {
        return strtolower((string) strtok(php_uname('s'), ' '));
    }

    /** CI name ('github-actions'/'gitlab-ci') or null outside CI. */
    protected function detectCi(): ?string
    {
        $ciName = CIEnvironment::detect();
        return $ciName === CIEnvironment::NONE ? null : $ciName;
    }

    /**
     * The 1/5/15-minute load averages, or an empty array when unavailable.
     * On Windows `sys_getloadavg()` is **not defined** (calling it is a fatal
     * "undefined function" error, BUG-25), so guard with function_exists first.
     *
     * @return array<int, float>
     */
    protected function loadAverage(): array
    {
        if (!$this->supportsLoadAverage()) {
            return [];
        }
        $load = sys_getloadavg();
        return is_array($load) ? $load : [];
    }

    /** Whether the platform exposes `sys_getloadavg()` (false on Windows). */
    protected function supportsLoadAverage(): bool
    {
        return function_exists('sys_getloadavg');
    }
}
