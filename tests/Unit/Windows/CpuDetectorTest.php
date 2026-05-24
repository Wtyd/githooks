<?php

declare(strict_types=1);

namespace Tests\Unit\Windows;

use PHPUnit\Framework\TestCase;
use Tests\Doubles\UnixCpuDetectorStub;
use Tests\Doubles\WindowsCpuDetectorNoExecStub;
use Tests\Doubles\WindowsCpuDetectorScriptedExecStub;
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

    /**
     * @test
     * Identical: when wmic exits 0 with a numeric output line, that value
     * becomes the result and the command was the wmic+stderrRedirect concat.
     */
    function windows_wmic_returns_numeric_output_and_uses_full_command_with_stderr_redirect()
    {
        $original = getenv('NUMBER_OF_PROCESSORS');
        putenv('NUMBER_OF_PROCESSORS');

        try {
            $detector = new WindowsCpuDetectorScriptedExecStub([
                'output' => ['NumberOfLogicalProcessors', '8', ''],
                'exit'   => 0,
            ]);

            $this->assertSame(8, $detector->detect());
            $this->assertCount(1, $detector->executed);
            $command = $detector->executed[0];
            // Order matters: the wmic invocation must come BEFORE the stderr
            // redirect, never after. This kills L34 Concat (operand swap).
            $this->assertStringStartsWith('wmic cpu get NumberOfLogicalProcessors ', $command);
            $this->assertNotSame('wmic cpu get NumberOfLogicalProcessors ', $command);
        } finally {
            if ($original === false) {
                putenv('NUMBER_OF_PROCESSORS');
            } else {
                putenv("NUMBER_OF_PROCESSORS=$original");
            }
        }
    }

    /**
     * @test
     * return null (not be read). Uses a real file that does NOT exist.
     */
    function read_file_contents_returns_null_for_unreadable_path()
    {
        $detector = new CpuDetector();
        $reflection = new \ReflectionMethod(CpuDetector::class, 'readFileContents');
        $reflection->setAccessible(true);

        $result = $reflection->invoke($detector, '/nonexistent/path/that/cannot/exist/anywhere');
        $this->assertNull($result);
    }

    /**
     * @test
     * contents, not null. Uses a real tmpfile so the production path runs
     * end-to-end (including is_readable + file_get_contents).
     */
    function read_file_contents_returns_contents_for_readable_file()
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'cpudetector_test_');
        $this->assertNotFalse($tmpPath);
        file_put_contents($tmpPath, "200000 100000\n");

        try {
            $detector = new CpuDetector();
            $reflection = new \ReflectionMethod(CpuDetector::class, 'readFileContents');
            $reflection->setAccessible(true);

            $result = $reflection->invoke($detector, $tmpPath);
            $this->assertSame("200000 100000\n", $result);
        } finally {
            @unlink($tmpPath);
        }
    }

    /**
     * @test
     * `exec()` must actually run. We verify with a portable command (`echo`
     * on Unix) that output and exit code propagate via the references.
     *
     * @group integration
     */
    function exec_command_invokes_real_exec_and_propagates_output_and_exit_code()
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Unix-only echo command');
        }

        $detector = new CpuDetector();
        $reflection = new \ReflectionMethod(CpuDetector::class, 'execCommand');
        $reflection->setAccessible(true);

        $output = [];
        $exitCode = -1;
        $reflection->invokeArgs($detector, ['echo cpudetector-real-exec', &$output, &$exitCode]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(['cpudetector-real-exec'], $output);
    }

    /**
     * pads its output with whitespace on some Windows hosts. Without trim,
     * is_numeric('  8  ') is false and the detector falls through to return 1.
     *
     * @test
     */
    function windows_wmic_trims_whitespace_in_output_lines()
    {
        $original = getenv('NUMBER_OF_PROCESSORS');
        putenv('NUMBER_OF_PROCESSORS');

        try {
            $detector = new WindowsCpuDetectorScriptedExecStub([
                'output' => ['NumberOfLogicalProcessors', '  8  ', ''],
                'exit'   => 0,
            ]);

            $this->assertSame(8, $detector->detect());
        } finally {
            if ($original === false) {
                putenv('NUMBER_OF_PROCESSORS');
            } else {
                putenv("NUMBER_OF_PROCESSORS=$original");
            }
        }
    }

    /**
     * `&&` → `||`): wmic reporting 0 logical processors must NOT be accepted
     * as a valid result — the detector must fall through to the sentinel 1.
     *
     * - With `>= 0`: 0 is accepted and returned, contradicting the contract.
     * - With `||`: the guard accepts a non-numeric value AS LONG AS the
     *   numeric one passes, or a non-positive numeric AS LONG AS it parses.
     *
     * @test
     */
    function windows_wmic_zero_falls_back_to_sentinel_one()
    {
        $original = getenv('NUMBER_OF_PROCESSORS');
        putenv('NUMBER_OF_PROCESSORS');

        try {
            $detector = new WindowsCpuDetectorScriptedExecStub([
                'output' => ['NumberOfLogicalProcessors', '0', ''],
                'exit'   => 0,
            ]);

            $this->assertSame(1, $detector->detect());
        } finally {
            if ($original === false) {
                putenv('NUMBER_OF_PROCESSORS');
            } else {
                putenv("NUMBER_OF_PROCESSORS=$original");
            }
        }
    }

    /**
     * for the sysctl branch): exit code 0 with an EMPTY output must NOT be
     * treated as a result — the detector must continue to /proc/cpuinfo.
     *
     * With `||`, the if-block enters and reads `(int) $output[0]`, which on
     * an empty array warns + returns 0 (PHP coerces null → 0).
     *
     * @test
     */
    function unix_sysctl_with_empty_output_falls_through_to_proc_cpuinfo()
    {
        $detector = new UnixCpuDetectorStub(
            [
                'nproc 2>/dev/null'             => ['output' => [], 'exit' => 127],
                'sysctl -n hw.ncpu 2>/dev/null' => ['output' => [], 'exit' => 0],
            ],
            $procCpuinfoCount = 6
        );

        $this->assertSame(6, $detector->detect());
    }
}
