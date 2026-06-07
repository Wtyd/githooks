<?php

declare(strict_types=1);

namespace Tests\Unit\Utils;

use Tests\Utils\TestCase\UnitTestCase;
use Tests\Doubles\UnixCpuDetectorStub;

/**
 * `CpuDetector::cgroupLimit()` exposes the container CPU cap for the diagnostics
 * block (FEAT-14), separate from the effective `detect()` count.
 */
class CpuDetectorCgroupLimitTest extends UnitTestCase
{
    private const NPROC = ['nproc 2>/dev/null' => ['output' => ['20'], 'exit' => 0]];

    /** @test */
    public function returns_null_when_no_cgroup_limit_in_force(): void
    {
        $detector = new UnixCpuDetectorStub(self::NPROC, 0, []);

        $this->assertNull($detector->cgroupLimit());
    }

    /** @test */
    public function returns_the_quota_limit_from_cgroup_v2(): void
    {
        $detector = new UnixCpuDetectorStub(self::NPROC, 0, [
            '/sys/fs/cgroup/cpu.max' => "200000 100000\n", // 2 cores
        ]);

        $this->assertSame(2, $detector->cgroupLimit());
    }

    /** @test */
    public function returns_null_for_unlimited_quota(): void
    {
        $detector = new UnixCpuDetectorStub(self::NPROC, 0, [
            '/sys/fs/cgroup/cpu.max' => "max 100000\n",
        ]);

        $this->assertNull($detector->cgroupLimit());
    }
}
