<?php

declare(strict_types=1);

namespace Tests\Unit\Output\Diagnostics;

use PHPUnit\Framework\TestCase;
use Tests\Doubles\DiagnosticsCollectorStub;
use Tests\Doubles\MemoryDetectorStub;
use Tests\Doubles\UnixCpuDetectorStub;

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
