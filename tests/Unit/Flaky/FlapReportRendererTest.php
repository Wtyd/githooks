<?php

declare(strict_types=1);

namespace Tests\Unit\Flaky;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Flaky\FlapReport;
use Wtyd\GitHooks\Flaky\FlapReportRenderer;
use Wtyd\GitHooks\Flaky\JunitReport;

class FlapReportRendererTest extends UnitTestCase
{
    /**
     * One test of every class the text report treats differently, a job
     * without junit that failed, an anomaly, a collision and an inconclusive job.
     */
    private static function report(): FlapReport
    {
        $row = static function (string $test, string $class, int $green, int $nongreen, int $neutral = 0, int $absent = 0): array {
            return ['job' => 'suite', 'test' => $test, 'class' => $class, 'green' => $green, 'nongreen' => $nongreen, 'neutral' => $neutral, 'absent' => $absent];
        };

        return new FlapReport(
            'tests',
            4,
            2,
            [
                $row('T::stable', FlapReport::STABLE_PASS, 3, 0),
                $row('T::flaky', FlapReport::FLAKY, 1, 2),
                $row('T::suspect', FlapReport::SUSPECT, 2, 1),
                $row('T::broken', FlapReport::STABLE_FAIL, 0, 3),
                $row('T::brokenSkip', FlapReport::STABLE_FAIL_WITH_SKIPS, 0, 2, 1),
                $row('T::skipped', FlapReport::ALWAYS_SKIPPED, 0, 0, 3),
            ],
            [
                ['name' => 'suite', 'junit' => 'junit.xml', 'passed' => 1, 'failed' => 3, 'skipped' => 0, 'readablePasses' => 3],
                ['name' => 'lint', 'junit' => null, 'passed' => 2, 'failed' => 2, 'skipped' => 0, 'readablePasses' => 0],
                ['name' => 'e2e', 'junit' => 'e2e.xml', 'passed' => 0, 'failed' => 4, 'skipped' => 0, 'readablePasses' => 0],
            ],
            [['pass' => 3, 'job' => 'suite', 'reason' => JunitReport::FILE_MISSING]],
            [['pass' => 2, 'job' => 'suite', 'test' => 'T::flaky']]
        );
    }

    /** @test */
    public function the_text_report_lists_what_needs_attention_and_summarises_the_rest(): void
    {
        $expected = implode("\n", [
            "Flaky test report: flow 'tests', 4 passes, flap threshold 2",
            '',
            '  FLAKY     1/3  passed  T::flaky  (suite)',
            '  SUSPECT   2/3  passed  T::suspect  (suite)',
            '  FAILING   0/3  passed  T::broken  (suite)',
            '  FAILING   0/3  passed  T::brokenSkip  (suite)',
            '',
            'Summary: 1 stable, 1 flaky, 1 suspect, 2 failing, 1 always skipped.',
            "Job 'lint' (no junit file) failed in 2 of 4 passes.",
            'Warnings:',
            "  - Pass 3, job 'suite': junit file was not written; the pass is left out of its counts.",
            "  - Pass 2, job 'suite': test 'T::flaky' reported more than once; the worst outcome was kept.",
            "  - Job 'e2e': no readable junit file in any pass; nothing was measured.",
        ]);

        $this->assertSame($expected, (new FlapReportRenderer())->renderText(self::report()));
    }

    /** @test */
    public function an_all_stable_text_report_has_no_list_and_no_warnings(): void
    {
        $report = new FlapReport('tests', 2, 1, [
            ['job' => 'suite', 'test' => 'T::a', 'class' => FlapReport::STABLE_PASS, 'green' => 2, 'nongreen' => 0, 'neutral' => 0, 'absent' => 0],
        ], [], [], []);

        $this->assertSame(
            "Flaky test report: flow 'tests', 2 passes, flap threshold 1\n\nSummary: 1 stable, 0 flaky, 0 suspect, 0 failing, 0 always skipped.",
            (new FlapReportRenderer())->renderText($report)
        );
    }

    /**
     * @test
     * @dataProvider anomalyReasons
     */
    public function each_anomaly_reason_reads_as_a_sentence(string $reason, string $sentence): void
    {
        $report = new FlapReport('tests', 2, 1, [], [], [['pass' => 1, 'job' => 'suite', 'reason' => $reason]], []);

        $this->assertStringContainsString(
            "  - Pass 1, job 'suite': junit file $sentence; the pass is left out of its counts.",
            (new FlapReportRenderer())->renderText($report)
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public function anomalyReasons(): array
    {
        return [
            'missing' => [JunitReport::FILE_MISSING, 'was not written'],
            'corrupt' => [JunitReport::FILE_CORRUPT, 'could not be parsed'],
            'stale'   => [JunitReport::FILE_STALE, 'from the previous pass could not be removed'],
        ];
    }

    /** @test */
    public function the_json_payload_has_its_own_versioned_schema(): void
    {
        $decoded = json_decode((new FlapReportRenderer())->renderJson(self::report()), true);

        $this->assertSame(
            ['version', 'flow', 'repeat', 'threshold', 'success', 'summary', 'tests', 'jobs', 'anomalies', 'collisions', 'inconclusiveJobs'],
            array_keys($decoded)
        );
        $this->assertSame([1, 'tests', 4, 2, false], [$decoded['version'], $decoded['flow'], $decoded['repeat'], $decoded['threshold'], $decoded['success']]);
    }

    /** @test */
    public function the_json_payload_renames_the_counts_for_readers(): void
    {
        $decoded = json_decode((new FlapReportRenderer())->renderJson(self::report()), true);

        $this->assertSame(
            ['job' => 'suite', 'test' => 'T::brokenSkip', 'class' => FlapReport::STABLE_FAIL_WITH_SKIPS, 'passed' => 0, 'failed' => 2, 'skipped' => 1, 'absent' => 0],
            $decoded['tests'][4]
        );
        $this->assertSame(['stable' => 1, 'flaky' => 1, 'suspect' => 1, 'failing' => 2, 'alwaysSkipped' => 1], $decoded['summary']);
    }

    /** @test */
    public function the_json_payload_passes_the_facts_through(): void
    {
        $report = self::report();
        $decoded = json_decode((new FlapReportRenderer())->renderJson($report), true);

        $this->assertSame(
            [$report->getJobs(), $report->getAnomalies(), $report->getCollisions(), ['e2e']],
            [$decoded['jobs'], $decoded['anomalies'], $decoded['collisions'], $decoded['inconclusiveJobs']]
        );
    }
}
