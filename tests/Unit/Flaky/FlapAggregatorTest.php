<?php

declare(strict_types=1);

namespace Tests\Unit\Flaky;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\Alfil\Flaky\FlapAggregator;
use Wtyd\Alfil\Flaky\FlapReport;
use Wtyd\Alfil\Flaky\JunitReport;

/**
 * Table A of tests/Unit/Flaky/factors.md, row by row. Each pass of the single
 * test `T::t` is written as one token:
 *   g = passed, n = failed, s = skipped, x = absent from a readable file,
 *   ! = the pass's junit file was not written (anomaly).
 */
class FlapAggregatorTest extends UnitTestCase
{
    private const JUNIT = ['suite' => 'junit.xml'];

    private static function junit(string $testcases): JunitReport
    {
        return JunitReport::fromString(
            "<?xml version=\"1.0\"?><testsuites><testsuite name=\"s\">$testcases</testsuite></testsuites>"
        );
    }

    private static function testcase(string $token, string $name = 't'): string
    {
        $children = ['g' => '', 'n' => '<failure/>', 's' => '<skipped/>'][$token];

        return "<testcase classname=\"T\" name=\"$name\">$children</testcase>";
    }

    /**
     * One pass per token; a second test `T::other` is always present so that
     * an `x` pass is a readable file the test is missing from.
     *
     * @return list<array{reports: array<string, JunitReport>, jobs: array<string, string>}>
     */
    private static function passes(string $tokens): array
    {
        $passes = [];
        foreach (str_split($tokens) as $token) {
            if ($token === '!') {
                $report = JunitReport::missing();
            } else {
                $report = self::junit(($token === 'x' ? '' : self::testcase($token)) . self::testcase('g', 'other'));
            }
            $passes[] = ['reports' => ['suite' => $report], 'jobs' => ['suite' => FlapReport::JOB_PASSED]];
        }
        return $passes;
    }

    /**
     * @return array{class: string, green: int, nongreen: int, neutral: int, absent: int}
     */
    private static function row(FlapReport $report, string $test = 'T::t'): array
    {
        foreach ($report->getTests() as $row) {
            if ($row['test'] === $test) {
                return [
                    'class'    => $row['class'],
                    'green'    => $row['green'],
                    'nongreen' => $row['nongreen'],
                    'neutral'  => $row['neutral'],
                    'absent'   => $row['absent'],
                ];
            }
        }
        self::fail("No row for $test");
    }

    /**
     * @test
     * @dataProvider decisionTable
     * @param array{int, int, int, int} $counts green, nongreen, neutral, absent
     */
    public function it_classifies_each_test_by_the_decision_table(string $passes, int $threshold, string $class, array $counts): void
    {
        $report = (new FlapAggregator($threshold))->aggregate('tests', self::JUNIT, self::passes($passes));

        $this->assertSame(
            ['class' => $class, 'green' => $counts[0], 'nongreen' => $counts[1], 'neutral' => $counts[2], 'absent' => $counts[3]],
            self::row($report)
        );
    }

    /**
     * @return array<string, array{string, int, string, array{int, int, int, int}}>
     */
    public function decisionTable(): array
    {
        return [
            // passes, threshold, class, [g, n, s, x]
            'row 1 · always passed'                     => ['ggg', 1, FlapReport::STABLE_PASS, [3, 0, 0, 0]],
            'row 2 · always failed'                     => ['nnn', 1, FlapReport::STABLE_FAIL, [0, 3, 0, 0]],
            'row 3 · mixed, min == threshold'           => ['ggnn', 2, FlapReport::FLAKY, [2, 2, 0, 0]],
            'row 3 · mixed, min > threshold'            => ['gggnnn', 2, FlapReport::FLAKY, [3, 3, 0, 0]],
            'row 3 · mixed, default threshold'          => ['gn', 1, FlapReport::FLAKY, [1, 1, 0, 0]],
            'row 4 · mixed, min < threshold'            => ['gggn', 2, FlapReport::SUSPECT, [3, 1, 0, 0]],
            'row 4 · rarer side is green'               => ['gnnn', 2, FlapReport::SUSPECT, [1, 3, 0, 0]],
            'row 5 · passes and skips'                  => ['gsg', 1, FlapReport::STABLE_PASS_WITH_SKIPS, [2, 0, 1, 0]],
            'row 5 · passes and absences'               => ['gxg', 1, FlapReport::STABLE_PASS_WITH_SKIPS, [2, 0, 0, 1]],
            'row 6 · fails and skips'                   => ['nsn', 1, FlapReport::STABLE_FAIL_WITH_SKIPS, [0, 2, 1, 0]],
            'row 6 · fails and absences'                => ['nxn', 1, FlapReport::STABLE_FAIL_WITH_SKIPS, [0, 2, 0, 1]],
            'row 7 · always skipped'                    => ['sss', 1, FlapReport::ALWAYS_SKIPPED, [0, 0, 3, 0]],
            'row 7 · skipped and absent'                => ['sxs', 1, FlapReport::ALWAYS_SKIPPED, [0, 0, 2, 1]],
            'precedence · mixed beats skips'            => ['gns', 1, FlapReport::FLAKY, [1, 1, 1, 0]],
            'precedence · mixed beats absences'         => ['gnx', 1, FlapReport::FLAKY, [1, 1, 0, 1]],
            'row 9 · unwritten pass is not green'       => ['n!n', 1, FlapReport::STABLE_FAIL, [0, 2, 0, 0]],
            'row 9 · unwritten pass beside a flake'     => ['g!n', 1, FlapReport::FLAKY, [1, 1, 0, 0]],
        ];
    }

    /**
     * @test
     * @dataProvider unreadableReports
     */
    public function an_unreadable_pass_is_reported_as_an_anomaly_with_its_reason(JunitReport $unreadable, string $reason): void
    {
        $passes = self::passes('gg');
        $passes[1]['reports']['suite'] = $unreadable;

        $report = (new FlapAggregator(1))->aggregate('tests', self::JUNIT, $passes);

        $this->assertSame([['pass' => 2, 'job' => 'suite', 'reason' => $reason]], $report->getAnomalies());
    }

    /**
     * @return array<string, array{JunitReport, string}>
     */
    public function unreadableReports(): array
    {
        return [
            'not written'       => [JunitReport::missing(), JunitReport::FILE_MISSING],
            'not parseable'     => [JunitReport::fromString('<testsuites>'), JunitReport::FILE_CORRUPT],
            'could not clear'   => [JunitReport::stale(), JunitReport::FILE_STALE],
        ];
    }

    /** @test */
    public function a_pass_without_a_report_for_a_junit_job_counts_as_not_written(): void
    {
        $passes = self::passes('g');
        $passes[] = ['reports' => [], 'jobs' => ['suite' => FlapReport::JOB_FAILED]];

        $report = (new FlapAggregator(1))->aggregate('tests', self::JUNIT, $passes);

        $this->assertSame([['pass' => 2, 'job' => 'suite', 'reason' => JunitReport::FILE_MISSING]], $report->getAnomalies());
    }

    /** @test */
    public function collisions_are_listed_with_their_pass(): void
    {
        $passes = self::passes('g');
        $passes[] = [
            'reports' => ['suite' => self::junit(self::testcase('g') . self::testcase('n'))],
            'jobs'    => ['suite' => FlapReport::JOB_FAILED],
        ];

        $report = (new FlapAggregator(1))->aggregate('tests', self::JUNIT, $passes);

        $this->assertSame([['pass' => 2, 'job' => 'suite', 'test' => 'T::t']], $report->getCollisions());
        $this->assertSame(FlapReport::FLAKY, self::row($report)['class']);
    }

    /** @test */
    public function the_same_test_id_in_two_jobs_is_two_rows(): void
    {
        $both = self::junit(self::testcase('g'));
        $passes = [['reports' => ['unit' => $both, 'feature' => $both], 'jobs' => []]];

        $report = (new FlapAggregator(1))->aggregate('tests', ['unit' => 'u.xml', 'feature' => 'f.xml'], $passes);

        $this->assertSame(['unit', 'feature'], array_column($report->getTests(), 'job'));
    }

    /** @test */
    public function job_rows_count_outcomes_for_jobs_with_and_without_junit(): void
    {
        $readable = self::junit(self::testcase('g'));
        $passes = [
            ['reports' => ['suite' => $readable], 'jobs' => ['suite' => FlapReport::JOB_PASSED, 'lint' => FlapReport::JOB_FAILED]],
            ['reports' => ['suite' => JunitReport::missing()], 'jobs' => ['suite' => FlapReport::JOB_FAILED, 'lint' => FlapReport::JOB_SKIPPED]],
            ['reports' => ['suite' => $readable], 'jobs' => ['suite' => FlapReport::JOB_PASSED, 'lint' => FlapReport::JOB_FAILED]],
        ];

        $report = (new FlapAggregator(1))->aggregate('tests', self::JUNIT, $passes);

        $this->assertSame([
            ['name' => 'suite', 'junit' => 'junit.xml', 'passed' => 2, 'failed' => 1, 'skipped' => 0, 'readablePasses' => 2],
            ['name' => 'lint', 'junit' => null, 'passed' => 0, 'failed' => 2, 'skipped' => 1, 'readablePasses' => 0],
        ], $report->getJobs());
    }

    /** @test */
    public function a_junit_job_that_never_ran_still_gets_a_row(): void
    {
        $passes = [['reports' => ['suite' => JunitReport::missing()], 'jobs' => []]];

        $report = (new FlapAggregator(1))->aggregate('tests', self::JUNIT, $passes);

        $this->assertSame(
            [['name' => 'suite', 'junit' => 'junit.xml', 'passed' => 0, 'failed' => 0, 'skipped' => 0, 'readablePasses' => 0]],
            $report->getJobs()
        );
        $this->assertSame([], $report->getTests());
    }

    /**
     * @test
     * @dataProvider thresholdsBelowOne
     */
    public function a_threshold_below_one_behaves_as_one(int $threshold): void
    {
        $report = (new FlapAggregator($threshold))->aggregate('tests', self::JUNIT, self::passes('gn'));

        $this->assertSame(1, $report->getThreshold());
        $this->assertSame(FlapReport::FLAKY, self::row($report)['class']);
    }

    /**
     * @return array<string, array{int}>
     */
    public function thresholdsBelowOne(): array
    {
        return ['zero' => [0], 'negative' => [-3]];
    }

    /** @test */
    public function the_report_carries_flow_and_number_of_passes(): void
    {
        $report = (new FlapAggregator(2))->aggregate('tests', self::JUNIT, self::passes('ggg'));

        $this->assertSame(['tests', 3, 2], [$report->getFlow(), $report->getRepeat(), $report->getThreshold()]);
    }
}
