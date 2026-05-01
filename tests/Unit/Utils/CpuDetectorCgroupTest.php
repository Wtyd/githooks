<?php

declare(strict_types=1);

namespace Tests\Unit\Utils;

use PHPUnit\Framework\TestCase;
use Tests\Doubles\UnixCpuDetectorStub;

/**
 * Decision-table tests for cgroup-aware CPU detection (BUG-4).
 *
 * Factors:
 *   - Host nproc (the value the detector sees from `nproc`).
 *   - cgroup v2 file `/sys/fs/cgroup/cpu.max`: absent, "max <period>", "<quota> <period>".
 *   - cgroup v1 quota `/sys/fs/cgroup/cpu/cpu.cfs_quota_us`: absent, -1, positive.
 *   - cgroup v1 period `/sys/fs/cgroup/cpu/cpu.cfs_period_us`: absent, positive.
 *
 * Expected: detect() returns min(nproc, ceil(quota / period)) when a quota is
 * in force, otherwise nproc verbatim. v2 takes precedence over v1.
 */
class CpuDetectorCgroupTest extends TestCase
{
    private const NPROC_RESPONSE = ['nproc 2>/dev/null' => ['output' => ['20'], 'exit' => 0]];
    private const V2_PATH = '/sys/fs/cgroup/cpu.max';
    private const V1_QUOTA_PATH = '/sys/fs/cgroup/cpu/cpu.cfs_quota_us';
    private const V1_PERIOD_PATH = '/sys/fs/cgroup/cpu/cpu.cfs_period_us';

    /**
     * @test
     * @dataProvider cgroupCases
     */
    public function detect_clamps_to_cgroup_limit(array $files, int $expected): void
    {
        $detector = new UnixCpuDetectorStub(self::NPROC_RESPONSE, 0, $files);
        $this->assertSame($expected, $detector->detect());
    }

    /**
     * Each row covers a distinct equivalence class of (cgroup state) → (output).
     *
     * @return array<string, array{0: array<string, ?string>, 1: int}>
     */
    public function cgroupCases(): array
    {
        return [
            // No cgroup files at all → nproc verbatim (host execution).
            'no cgroup files' => [[], 20],

            // v2: "max" means unlimited → nproc verbatim.
            'v2 unlimited (max)' => [
                [self::V2_PATH => "max 100000\n"],
                20,
            ],

            // v2: quota < period * nproc → clamp.
            'v2 quota=200000 period=100000 → 2 cores' => [
                [self::V2_PATH => "200000 100000\n"],
                2,
            ],

            // v2: boundary — quota == period * nproc → equal to nproc, no clamp visible.
            'v2 quota equals nproc * period (boundary)' => [
                [self::V2_PATH => "2000000 100000\n"], // 20 cores worth
                20,
            ],

            // v2: quota > period * nproc → still nproc (min wins).
            'v2 quota larger than host → nproc cap' => [
                [self::V2_PATH => "9999999 100000\n"],
                20,
            ],

            // v2: ceil rounds up partial CPU (250% → 3).
            'v2 fractional 250% → ceil to 3' => [
                [self::V2_PATH => "250000 100000\n"],
                3,
            ],

            // v1: -1 means unlimited → nproc verbatim.
            'v1 quota=-1 (unlimited)' => [
                [
                    self::V1_QUOTA_PATH  => "-1\n",
                    self::V1_PERIOD_PATH => "100000\n",
                ],
                20,
            ],

            // v1: explicit limit → clamp.
            'v1 quota=400000 period=100000 → 4 cores' => [
                [
                    self::V1_QUOTA_PATH  => "400000\n",
                    self::V1_PERIOD_PATH => "100000\n",
                ],
                4,
            ],

            // v2 takes precedence over v1 when both are present.
            'v2 wins over v1 when both set' => [
                [
                    self::V2_PATH        => "100000 100000\n", // 1 core
                    self::V1_QUOTA_PATH  => "800000\n",
                    self::V1_PERIOD_PATH => "100000\n",
                ],
                1,
            ],

            // v2 garbage line → fallback (treated as unlimited).
            'v2 garbage falls back to nproc' => [
                [self::V2_PATH => "abc\n"],
                20,
            ],

            // cpuset v2: contiguous range "0-3" = 4 CPUs.
            'cpuset v2 range 0-3 → 4' => [
                ['/sys/fs/cgroup/cpuset.cpus.effective' => "0-3\n"],
                4,
            ],

            // cpuset v2: mixed list "0-3,8,10-11" = 4 + 1 + 2 = 7 CPUs.
            'cpuset v2 mixed list → 7' => [
                ['/sys/fs/cgroup/cpuset.cpus.effective' => "0-3,8,10-11\n"],
                7,
            ],

            // cpuset v2 effective wins over v2 cpu.max when it is the lower limit.
            'cpu.max=4, cpuset=2 → 2 (lower limit wins)' => [
                [
                    self::V2_PATH                            => "400000 100000\n",
                    '/sys/fs/cgroup/cpuset.cpus.effective'   => "0-1\n",
                ],
                2,
            ],

            // cpuset v1 (legacy path) is also honoured when v2 is absent.
            'cpuset v1 legacy path → 3' => [
                ['/sys/fs/cgroup/cpuset/cpuset.cpus' => "0,2,4\n"],
                3,
            ],
        ];
    }
}
