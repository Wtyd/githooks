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

            // ---- Adversarial coverage rows (Infection 2026-05-06 hardening) ----

            // v2 cpu.max with non-numeric quota and numeric period: must reject
            // (count<2 not the issue, !is_numeric($parts[0]) catches it).
            'v2 cpu.max "abc 100000" → fallback' => [
                [self::V2_PATH => "abc 100000\n"],
                20,
            ],

            // v2 cpu.max with numeric quota and non-numeric period: must reject
            // (!is_numeric($parts[1]) is the operative branch).
            'v2 cpu.max "100000 abc" → fallback' => [
                [self::V2_PATH => "100000 abc\n"],
                20,
            ],

            // v1 with only quota present, period absent → both must be readable
            // (LogicalOr `||` in the null-guard, killed by mutating to `&&`).
            'v1 only quota present → fallback' => [
                [self::V1_QUOTA_PATH => "400000\n"],
                20,
            ],

            // v1 with only period present, quota absent → fallback.
            'v1 only period present → fallback' => [
                [self::V1_PERIOD_PATH => "100000\n"],
                20,
            ],

            // v2 cpu.max + cpuset both present, distinct values: cpu.max returns
            // 4, cpuset returns 2 → min wins (kills mutants on `Coalesce` order).
            // (already covered by 'cpu.max=4, cpuset=2 → 2' above)

            // Empty cpuset string (whitespace only) → countCpusetEntries returns 0
            // → readCpusetLimit returns null (kills `$count > 0` → `>= 0` mutation).
            'cpuset whitespace only → fallback' => [
                ['/sys/fs/cgroup/cpuset.cpus.effective' => "   \n"],
                20,
            ],

            // Quota=0 (boundary): divideQuota must return null (kills `<=` → `<`
            // mutation that would let 0 through and produce ceil(0/period)=0).
            'v2 quota=0 → fallback (divideQuota guards <=)' => [
                [self::V2_PATH => "0 100000\n"],
                20,
            ],

            // Period=0 (boundary): divideQuota guards both sides; without guard
            // a division by zero would happen.
            'v2 period=0 → fallback (divideQuota guards <=)' => [
                [self::V2_PATH => "100000 0\n"],
                20,
            ],

            // Fractional quota that distinguishes ceil from round/floor:
            // 220% / 100% = 2.2 → ceil → 3 (round → 2, floor → 2).
            'v2 fractional 220% → ceil to 3 (kills RoundingFamily)' => [
                [self::V2_PATH => "220000 100000\n"],
                3,
            ],

            // cpuset with malformed double-dash range "0-3-5": current code uses
            // explode(..., 2) so rangeEnd parses as "3-5" which fails is_numeric
            // → segment counts as 0 → total cpuset count is 0 → null (fallback).
            // Mutating explode limit from 2 → 3 would change list destructuring
            // and is killed by this case.
            'cpuset malformed "0-3-5" → fallback' => [
                ['/sys/fs/cgroup/cpuset.cpus.effective' => "0-3-5\n"],
                20,
            ],

            // cpuset segment "0-x" with non-numeric end: is_numeric guard rejects
            // → returns 0 → cpuset total is 0 → fallback.
            'cpuset segment "0-x" → fallback (is_numeric guard)' => [
                ['/sys/fs/cgroup/cpuset.cpus.effective' => "0-x\n"],
                20,
            ],

            // cpuset segment "x-3" symmetric: kills LogicalAnd mutation that
            // weakens both numeric checks to OR.
            'cpuset segment "x-3" → fallback' => [
                ['/sys/fs/cgroup/cpuset.cpus.effective' => "x-3\n"],
                20,
            ],

            // cpuset single-CPU range "5-5" (boundary): rangeEnd >= rangeStart
            // is true, count = (5-5)+1 = 1. Kills `>=` → `>` mutation.
            'cpuset range "5-5" → 1' => [
                ['/sys/fs/cgroup/cpuset.cpus.effective' => "5-5\n"],
                1,
            ],

            // cpuset with non-numeric scalar segment: "0,abc,5" must skip "abc"
            // and count 0 + 1 (5) = 1 (not 2 or 3). Kills the `: 0` ternary
            // mutations to `: -1` and `: 1`.
            'cpuset "0,abc,5" → 2 (skip non-numeric)' => [
                ['/sys/fs/cgroup/cpuset.cpus.effective' => "0,abc,5\n"],
                2,
            ],

            // cpuset reversed range "5-1" where end < start: rangeEnd >= rangeStart
            // fails → segment count 0 → fallback.
            'cpuset reversed range "5-1" → fallback' => [
                ['/sys/fs/cgroup/cpuset.cpus.effective' => "5-1\n"],
                20,
            ],

            // Kills L3999 (Coalesce swap on readCpusetLimit): v2 cpuset.effective
            // wins over the v1 legacy path when both files are present.
            // Original: cpuset.effective (??) cpuset/cpuset.cpus → v2 value (2).
            // Mutant: cpuset/cpuset.cpus (??) cpuset.effective → v1 value (8).
            'cpuset v2 effective wins over v1 legacy when both present' => [
                [
                    '/sys/fs/cgroup/cpuset.cpus.effective' => "0-1\n",   // 2 cpus (v2)
                    '/sys/fs/cgroup/cpuset/cpuset.cpus'    => "0-7\n",   // 8 cpus (v1 legacy)
                ],
                2,
            ],
        ];
    }

    /**
     * Kills L4038 (LessThanOrEqualTo `$quota <= 0` → `$quota < 0` on
     * `divideQuota`): the helper must reject quota=0 directly, returning
     * null. The cgroup decision-table above hides this contract because
     * `clampToCgroupLimit($limit < 1)` absorbs a 0 return aguas abajo.
     * Testing `divideQuota` in isolation closes the local contract.
     *
     * @test
     * @dataProvider divideQuotaBoundariesProvider
     */
    public function divide_quota_rejects_non_positive_inputs_directly(int $quota, int $period, ?int $expected): void
    {
        $detector = new UnixCpuDetectorStub([]);
        $reflection = new \ReflectionMethod(\Wtyd\GitHooks\Utils\CpuDetector::class, 'divideQuota');
        $reflection->setAccessible(true);

        $this->assertSame($expected, $reflection->invoke($detector, $quota, $period));
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: ?int}>
     */
    public function divideQuotaBoundariesProvider(): array
    {
        return [
            // Kills L4038 `<` → would let quota=0 through and produce ceil(0/period)=0.
            'quota=0 is rejected'             => [0, 100000, null],
            // Symmetric boundary on quota side: negative.
            'negative quota is rejected'      => [-1, 100000, null],
            'cgroup v1 sentinel -1'           => [-1, 100000, null],
            // Period boundary mirrors the same guard for the other operand.
            'period=0 is rejected'            => [100000, 0, null],
            'negative period is rejected'     => [100000, -1, null],
            // Happy path: ceil division.
            'happy 200000/100000 → 2'         => [200000, 100000, 2],
            'fractional 220000/100000 → 3'    => [220000, 100000, 3],  // ceil(2.2)
            'fractional 100000/100000 → 1'    => [100000, 100000, 1],
        ];
    }
}
