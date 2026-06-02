<?php

declare(strict_types=1);

namespace Tests\Unit\Utils;

use PHPUnit\Framework\TestCase;
use Tests\Doubles\MemoryDetectorStub;

/**
 * System-memory detection across platforms (FEAT-14).
 *
 * Factors: platform (linux/macos/windows) × source availability (/proc/meminfo,
 * cgroup limit present/absent, vm_stat present/absent). Unavailable → null,
 * never throws (AC-004).
 */
class MemoryDetectorTest extends TestCase
{
    /** @test */
    public function windows_reports_both_fields_null(): void
    {
        $detector = new MemoryDetectorStub('windows');

        $this->assertSame(['availableMb' => null, 'totalMb' => null], $detector->detect());
    }

    /** @test */
    public function linux_reads_proc_meminfo_in_mb(): void
    {
        $meminfo = "MemTotal:       65536000 kB\nMemFree: 1000 kB\nMemAvailable:    1269760 kB\n";
        $detector = new MemoryDetectorStub('linux', ['/proc/meminfo' => $meminfo]);

        $this->assertSame(
            ['availableMb' => 1240, 'totalMb' => 64000], // 1269760/1024=1240 ; 65536000/1024=64000
            $detector->detect()
        );
    }

    /** @test */
    public function linux_clamps_to_cgroup_limit_when_smaller_than_host(): void
    {
        $meminfo = "MemTotal:       65536000 kB\nMemAvailable:    60000000 kB\n";
        $detector = new MemoryDetectorStub('linux', [
            '/proc/meminfo'                  => $meminfo,
            '/sys/fs/cgroup/memory.max'      => (string) (2048 * 1024 * 1024), // 2048 MB
            '/sys/fs/cgroup/memory.current'  => (string) (512 * 1024 * 1024),  // 512 MB used
        ]);

        $out = $detector->detect();
        $this->assertSame(2048, $out['totalMb']);
        $this->assertSame(1536, $out['availableMb']); // 2048 - 512
    }

    /** @test */
    public function linux_ignores_cgroup_unlimited_sentinel_max(): void
    {
        $meminfo = "MemTotal:       8388608 kB\nMemAvailable:    4194304 kB\n";
        $detector = new MemoryDetectorStub('linux', [
            '/proc/meminfo'             => $meminfo,
            '/sys/fs/cgroup/memory.max' => "max\n",
        ]);

        $this->assertSame(['availableMb' => 4096, 'totalMb' => 8192], $detector->detect());
    }

    /** @test */
    public function linux_returns_null_when_meminfo_unreadable(): void
    {
        $detector = new MemoryDetectorStub('linux'); // no files

        $this->assertSame(['availableMb' => null, 'totalMb' => null], $detector->detect());
    }

    /** @test */
    public function macos_reads_total_from_sysctl_and_available_from_vm_stat(): void
    {
        $vmStat = "Mach Virtual Memory Statistics: (page size of 4096 bytes)\n"
            . "Pages free:                          100000.\n"
            . "Pages inactive:                      200000.\n"
            . "Pages speculative:                    50000.\n";
        $detector = new MemoryDetectorStub('macos', [], [
            'sysctl -n hw.memsize 2>/dev/null' => ['output' => [(string) (16 * 1024 * 1024 * 1024)], 'exit' => 0],
            'vm_stat 2>/dev/null'              => ['output' => explode("\n", $vmStat), 'exit' => 0],
        ]);

        $out = $detector->detect();
        $this->assertSame(16384, $out['totalMb']);            // 16 GB
        // (100000 + 200000 + 50000) * 4096 / 1024 / 1024 = 1367 MB
        $this->assertSame(1367, $out['availableMb']);
    }

    /** @test */
    public function macos_available_is_null_when_vm_stat_unavailable(): void
    {
        $detector = new MemoryDetectorStub('macos', [], [
            'sysctl -n hw.memsize 2>/dev/null' => ['output' => [(string) (8 * 1024 * 1024 * 1024)], 'exit' => 0],
            // vm_stat not scripted → exit 127
        ]);

        $out = $detector->detect();
        $this->assertSame(8192, $out['totalMb']);
        $this->assertNull($out['availableMb']);
    }
}
