<?php

declare(strict_types=1);

namespace Tests\Unit\Windows;

use PHPUnit\Framework\TestCase;
use Tests\Doubles\UnixCpuDetectorStub;
use Tests\Doubles\WindowsCpuDetectorNoExecStub;
use Tests\Doubles\WindowsCpuDetectorStub;
use Wtyd\GitHooks\Utils\CpuDetector;

/**
 * Tests CPU detection logic including Windows paths.
 * Uses stubs to test Windows code paths on Linux.
 */
class CpuDetectorTest extends TestCase
{
    /** @test */
    function it_detects_cpus_on_current_platform()
    {
        $detector = new CpuDetector();
        $cpus = $detector->detect();

        $this->assertGreaterThanOrEqual(1, $cpus);
    }

    /** @test */
    function windows_path_on_unix_does_not_create_stray_nul_file()
    {
        $original = getenv('NUMBER_OF_PROCESSORS');
        putenv('NUMBER_OF_PROCESSORS');

        $nulFile = getcwd() . DIRECTORY_SEPARATOR . 'NUL';
        @unlink($nulFile);

        try {
            (new WindowsCpuDetectorStub())->detect();
            $this->assertFileDoesNotExist($nulFile);
        } finally {
            @unlink($nulFile);
            if ($original === false) {
                putenv('NUMBER_OF_PROCESSORS');
            } else {
                putenv("NUMBER_OF_PROCESSORS=$original");
            }
        }
    }

    /** @test */
    function windows_detects_via_number_of_processors_env()
    {
        $original = getenv('NUMBER_OF_PROCESSORS');

        putenv('NUMBER_OF_PROCESSORS=8');

        $detector = new WindowsCpuDetectorStub();
        $this->assertSame(8, $detector->detect());

        if ($original === false) {
            putenv('NUMBER_OF_PROCESSORS');
        } else {
            putenv("NUMBER_OF_PROCESSORS=$original");
        }
    }

    /** @test */
    function windows_returns_1_when_env_is_missing()
    {
        $original = getenv('NUMBER_OF_PROCESSORS');

        putenv('NUMBER_OF_PROCESSORS');

        $detector = new WindowsCpuDetectorNoExecStub();
        $this->assertSame(1, $detector->detect());

        if ($original !== false) {
            putenv("NUMBER_OF_PROCESSORS=$original");
        }
    }

    /** @test */
    function windows_returns_1_when_env_is_zero()
    {
        $original = getenv('NUMBER_OF_PROCESSORS');

        putenv('NUMBER_OF_PROCESSORS=0');

        $detector = new WindowsCpuDetectorNoExecStub();
        $this->assertSame(1, $detector->detect());

        if ($original === false) {
            putenv('NUMBER_OF_PROCESSORS');
        } else {
            putenv("NUMBER_OF_PROCESSORS=$original");
        }
    }

    /**
     * @test
     * Kills L47 FunctionCallRemoval (`exec('nproc')`) and L48 Identical/LogicalAnd:
     * exact value of the detected cpu count via scripted exec.
     */
    function unix_reads_exact_count_from_nproc_when_available()
    {
        $detector = new UnixCpuDetectorStub([
            'nproc 2>/dev/null' => ['output' => ['12'], 'exit' => 0],
        ]);

        $this->assertSame(12, $detector->detect());
        $this->assertSame(['nproc 2>/dev/null'], $detector->executed);
    }

    /**
     * @test
     * Kills L48 Identical `===`→`!==` and LogicalAnd `&&`→`||`: when nproc fails
     * (non-zero exit), detection falls through to sysctl and onwards to /proc/cpuinfo,
     * returning the fallback value (1), not the first output element.
     */
    function unix_falls_back_to_sysctl_when_nproc_exits_nonzero()
    {
        $detector = new UnixCpuDetectorStub([
            'nproc 2>/dev/null'             => ['output' => ['garbage'], 'exit' => 127],
            'sysctl -n hw.ncpu 2>/dev/null' => ['output' => ['8'],       'exit' => 0],
        ]);

        $this->assertSame(8, $detector->detect());
        $this->assertSame(
            ['nproc 2>/dev/null', 'sysctl -n hw.ncpu 2>/dev/null'],
            $detector->executed
        );
    }

    /**
     * @test
     * Kills L48 LogicalAnd: `exitCode === 0 && !empty($output)` — with exit=0
     * but empty output, the mutation to `||` would use garbage `$output[0]`.
     */
    function unix_does_not_use_empty_output_even_when_exit_is_zero()
    {
        $detector = new UnixCpuDetectorStub([
            'nproc 2>/dev/null'             => ['output' => [], 'exit' => 0],
            'sysctl -n hw.ncpu 2>/dev/null' => ['output' => [], 'exit' => 127],
        ], $procCpuinfoCount = 0);

        $this->assertSame(1, $detector->detect());
    }

    /**
     * @test
     * Kills L39-style fallbacks: when every exec fails and /proc/cpuinfo is absent,
     * the sentinel return value must be exactly 1 (not 0, not 2, not -1).
     */
    function unix_returns_exact_fallback_value_when_all_probes_fail()
    {
        $detector = new UnixCpuDetectorStub([], $procCpuinfoCount = 0);

        $this->assertSame(1, $detector->detect());
    }

    /**
     * @test
     * When /proc/cpuinfo is the only source, its count becomes the result —
     * ensuring the fallback branch is actually reached and not shadowed.
     */
    function unix_uses_proc_cpuinfo_count_when_exec_probes_all_fail()
    {
        $detector = new UnixCpuDetectorStub([
            'nproc 2>/dev/null'             => ['output' => [], 'exit' => 127],
            'sysctl -n hw.ncpu 2>/dev/null' => ['output' => [], 'exit' => 127],
        ], $procCpuinfoCount = 4);

        $this->assertSame(4, $detector->detect());
    }
}
