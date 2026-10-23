<?php

declare(strict_types=1);

namespace Tests\Unit\Flaky;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\Alfil\Flaky\FlapReport;

/**
 * Exit policy of table A (tests/Unit/Flaky/factors.md): the classes that fail
 * the command, and row 9b (a junit job with nothing measured).
 */
class FlapReportTest extends UnitTestCase
{
    /**
     * @param string[] $classes
     * @param list<array{name: string, junit: ?string, passed: int, failed: int, skipped: int, readablePasses: int}> $jobs
     */
    private static function report(array $classes, array $jobs = []): FlapReport
    {
        $tests = [];
        foreach ($classes as $index => $class) {
            $tests[] = ['job' => 'suite', 'test' => "T::t$index", 'class' => $class, 'green' => 1, 'nongreen' => 0, 'neutral' => 0, 'absent' => 0];
        }

        return new FlapReport('tests', 3, 1, $tests, $jobs, [], []);
    }

    /**
     * @test
     * @dataProvider classesAndExitCodes
     */
    public function each_class_maps_to_its_exit_code(string $class, int $exitCode): void
    {
        $report = self::report([FlapReport::STABLE_PASS, $class]);

        $this->assertSame($exitCode, $report->exitCode());
    }

    /**
     * @return array<string, array{string, int}>
     */
    public function classesAndExitCodes(): array
    {
        return [
            'stable-pass'            => [FlapReport::STABLE_PASS, 0],
            'stable-pass-with-skips' => [FlapReport::STABLE_PASS_WITH_SKIPS, 0],
            'always-skipped'         => [FlapReport::ALWAYS_SKIPPED, 0],
            'suspect (below threshold)' => [FlapReport::SUSPECT, 0],
            'flaky'                  => [FlapReport::FLAKY, 1],
            'stable-fail'            => [FlapReport::STABLE_FAIL, 1],
            'stable-fail-with-skips' => [FlapReport::STABLE_FAIL_WITH_SKIPS, 1],
        ];
    }

    /** @test */
    public function no_tests_at_all_is_success(): void
    {
        $this->assertSame(0, self::report([])->exitCode());
    }

    /**
     * @test
     * @dataProvider measuredJobs
     */
    public function a_junit_job_with_no_readable_pass_is_inconclusive_and_fails(?string $junit, int $readablePasses, array $inconclusive, int $exitCode): void
    {
        $report = self::report([FlapReport::STABLE_PASS], [
            ['name' => 'suite', 'junit' => $junit, 'passed' => 3, 'failed' => 0, 'skipped' => 0, 'readablePasses' => $readablePasses],
        ]);

        $this->assertSame($inconclusive, $report->getInconclusiveJobs());
        $this->assertSame($exitCode, $report->exitCode());
    }

    /**
     * @return array<string, array{?string, int, string[], int}>
     */
    public function measuredJobs(): array
    {
        return [
            'junit, no readable pass'  => ['junit.xml', 0, ['suite'], 1],
            'junit, one readable pass' => ['junit.xml', 1, [], 0],
            'no junit (not measured)'  => [null, 0, [], 0],
        ];
    }

    /** @test */
    public function it_filters_tests_by_class_keeping_order(): void
    {
        $report = self::report([FlapReport::FLAKY, FlapReport::STABLE_PASS, FlapReport::FLAKY]);

        $this->assertSame(['T::t0', 'T::t2'], array_column($report->getTestsOfClass(FlapReport::FLAKY), 'test'));
    }
}
