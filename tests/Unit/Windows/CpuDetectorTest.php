<?php

declare(strict_types=1);

namespace Tests\Unit\Windows;

use PHPUnit\Framework\TestCase;
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
}
