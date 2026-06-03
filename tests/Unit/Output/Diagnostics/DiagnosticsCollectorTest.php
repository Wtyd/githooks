<?php

declare(strict_types=1);

namespace Tests\Unit\Output\Diagnostics;

use PHPUnit\Framework\TestCase;
use Tests\Doubles\DiagnosticsCollectorStub;
use Tests\Doubles\MemoryDetectorStub;
use Tests\Doubles\UnixCpuDetectorStub;
use Wtyd\GitHooks\Output\Diagnostics\DiagnosticsCollector;

class DiagnosticsCollectorTest extends TestCase
{
    private function cpu(): UnixCpuDetectorStub
    {
        return new UnixCpuDetectorStub(
            ['nproc 2>/dev/null' => ['output' => ['8'], 'exit' => 0]],
            0,
            []
        );
    }

    /** @test */
    public function collects_a_full_linux_snapshot(): void
    {
        $memory = new MemoryDetectorStub('linux', [
            '/proc/meminfo' => "MemTotal: 67108864 kB\nMemAvailable: 12700672 kB\n",
        ]);
        $collector = new DiagnosticsCollectorStub(
            $this->cpu(),
            $memory,
            '3.5.0',
            'linux',
            'gitlab-ci',
            [1.2, 0.8, 0.5]
        );

        $d = $collector->collect();

        $this->assertSame('3.5.0', $d->getVersion());
        $this->assertSame('linux', $d->getPlatform());
        $this->assertSame('gitlab-ci', $d->getCi());
        $this->assertSame(8, $d->getCpuDetected());
        $this->assertNull($d->getCpuCgroupLimit());
        $this->assertSame(12403, $d->getMemAvailableMb()); // 12700672/1024
        $this->assertSame(65536, $d->getMemTotalMb());      // 67108864/1024
        $this->assertSame(1.2, $d->getLoadAvg1());
        $this->assertSame(0.8, $d->getLoadAvg5());
        $this->assertSame(0.5, $d->getLoadAvg15());
    }

    /** @test */
    public function nulls_unavailable_fields_without_breaking(): void
    {
        // Windows-ish: no memory, no load avg.
        $collector = new DiagnosticsCollectorStub(
            $this->cpu(),
            new MemoryDetectorStub('windows'),
            '3.5.0',
            'windows',
            null,
            []
        );

        $d = $collector->collect();

        $this->assertNull($d->getMemAvailableMb());
        $this->assertNull($d->getMemTotalMb());
        $this->assertNull($d->getLoadAvg1());
        $this->assertNull($d->getCi());
        $this->assertSame(8, $d->getCpuDetected());
    }

    /**
     * @test
     *
     * BUG-25: on Windows `sys_getloadavg()` is undefined, so calling it is a
     * fatal "undefined function" error (the diagnostics block is auto-on in CI,
     * so it aborted every Windows run). The real loadAverage() must short-circuit
     * to [] when the platform doesn't expose the function, never calling it.
     * Fails on any platform if the function_exists guard is removed.
     */
    public function load_average_short_circuits_when_unsupported(): void
    {
        $collector = new class extends DiagnosticsCollector {
            protected function supportsLoadAverage(): bool
            {
                return false;
            }

            /** @return array<int, float> */
            public function exposeLoadAverage(): array
            {
                return $this->loadAverage();
            }
        };

        $this->assertSame([], $collector->exposeLoadAverage());
    }

    /**
     * The version injected by the app layer (app('git.version'), the same source
     * as `--version`) must win over the PrettyVersions fallback. This is the fix
     * for the runtime block reporting 'unknown' in the distributed .phar.
     *
     * @test
     */
    public function uses_the_injected_version_over_the_composer_fallback(): void
    {
        $collector = new DiagnosticsCollector($this->cpu(), new MemoryDetectorStub('linux'), '9.9.9-test');

        $this->assertSame('9.9.9-test', $collector->collect()->getVersion());
    }

    /**
     * An empty/absent injected version must fall through to the fallback, never
     * surface as an empty version string.
     *
     * @test
     */
    public function falls_back_when_the_injected_version_is_empty(): void
    {
        $collector = new DiagnosticsCollector($this->cpu(), new MemoryDetectorStub('linux'), '');

        $this->assertNotSame('', $collector->collect()->getVersion());
    }

    /** @test */
    public function now_formats_an_iso8601_timestamp_with_milliseconds(): void
    {
        // 2026-05-13T14:23:08.123456 UTC = 1778761388.123456
        $collector = new DiagnosticsCollectorStub(
            $this->cpu(),
            new MemoryDetectorStub('linux'),
            '3.5.0',
            'linux',
            null,
            [],
            1778761388.123456
        );

        $iso = $collector->now();

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}[+-]\d{2}:\d{2}$/',
            $iso
        );
        $this->assertNotFalse(strtotime($iso));
    }
}
