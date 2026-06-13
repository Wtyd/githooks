<?php

declare(strict_types=1);

namespace Tests\Unit\Utils;

use Tests\Utils\TestCase\UnitTestCase;
use Tests\Doubles\MemoryDetectorStub;

/**
 * System-memory detection across platforms (FEAT-14).
 *
 * Factors: platform (linux/macos/windows) × source availability (/proc/meminfo,
 * cgroup limit present/absent, vm_stat present/absent). Unavailable → null,
 * never throws (AC-004).
 */
class MemoryDetectorTest extends UnitTestCase
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

    /**
     * macOS detection factor table (Infection hardening). Each row pins a single
     * equivalence class of the compound guards so the logical mutants die:
     *  - `detectMacOS` total guard L81: `$exit === 0 && isset($output[0]) && is_numeric(trim(...))`
     *  - `readMacOSAvailableMb` gate L93: `$exit !== 0 || $output === []`
     *  - `readMacOSAvailableMb` free/inactive gate L104: `$free === null && $inactive === null`
     *
     * The page accumulation defaults L107 (`?? 0`) are intentionally NOT pinned:
     * a single page (4 KB) is invisible after `/1024/1024` to MB — those mutants
     * are equivalent at MB granularity.
     *
     * @test
     * @dataProvider macosDetectionProvider
     * @param array{output: array<int,string>, exit: int} $sysctl
     * @param array{output: array<int,string>, exit: int} $vmStat
     */
    public function macos_detection_factor_table(array $sysctl, array $vmStat, ?int $expectedTotal, ?int $expectedAvailable): void
    {
        $detector = new MemoryDetectorStub('macos', [], [
            'sysctl -n hw.memsize 2>/dev/null' => $sysctl,
            'vm_stat 2>/dev/null'              => $vmStat,
        ]);

        $this->assertSame(
            ['availableMb' => $expectedAvailable, 'totalMb' => $expectedTotal],
            $detector->detect()
        );
    }

    public function macosDetectionProvider(): array
    {
        $okSysctl = ['output' => [(string) (16 * 1024 * 1024 * 1024)], 'exit' => 0]; // 16384 MB
        // page size 4096: free=100000 → 390 MB; free+inactive(200000) → 1171 MB; inactive=200000 → 781 MB
        $vm = function (array $lines): array {
            return ['output' => array_merge(['Mach Virtual Memory Statistics: (page size of 4096 bytes)'], $lines), 'exit' => 0];
        };
        $okVm = $vm(['Pages free:                          100000.']);

        return [
            // --- total guard (sysctl, L81) ---
            'sysctl exit != 0 → total null'        => [['output' => [(string) (16 * 1024 * 1024 * 1024)], 'exit' => 1], $okVm, null, 390],
            'sysctl no output[0] → total null'     => [['output' => [], 'exit' => 0], $okVm, null, 390],
            'sysctl non-numeric → total null'      => [['output' => ['not-a-number'], 'exit' => 0], $okVm, null, 390],
            'sysctl ok → total set'                => [$okSysctl, $okVm, 16384, 390],

            // --- available gate (vm_stat, L93) ---
            'vm_stat exit != 0 → available null'   => [$okSysctl, ['output' => ['whatever'], 'exit' => 1], 16384, null],
            'vm_stat empty output → available null' => [$okSysctl, ['output' => [], 'exit' => 0], 16384, null],

            // --- free/inactive gate (L104) ---
            'only free present → counts'           => [$okSysctl, $vm(['Pages free:                          100000.']), 16384, 390],
            'only inactive present → counts'       => [$okSysctl, $vm(['Pages inactive:                      200000.']), 16384, 781],
            'neither free nor inactive → null'     => [$okSysctl, $vm(['Pages speculative:                    50000.']), 16384, null],
        ];
    }
}
