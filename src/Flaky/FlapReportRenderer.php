<?php

declare(strict_types=1);

namespace Wtyd\Alfil\Flaky;

/**
 * Renders a {@see FlapReport} as the text table `flow --repeat` prints, or as
 * its JSON payload (`version: 1`, its own schema — not the JSON v2 of a run).
 */
final class FlapReportRenderer
{
    public const SCHEMA_VERSION = 1;

    /** Classes listed one by one in the text report, in this order, with their label. */
    private const LISTED = [
        FlapReport::FLAKY                  => 'FLAKY',
        FlapReport::SUSPECT                => 'SUSPECT',
        FlapReport::STABLE_FAIL            => 'FAILING',
        FlapReport::STABLE_FAIL_WITH_SKIPS => 'FAILING',
    ];

    public function renderText(FlapReport $report): string
    {
        $lines = [
            sprintf(
                "Flaky test report: flow '%s', %d passes, flap threshold %d",
                $report->getFlow(),
                $report->getRepeat(),
                $report->getThreshold()
            ),
            '',
        ];

        foreach (self::LISTED as $class => $label) {
            foreach ($report->getTestsOfClass($class) as $row) {
                $lines[] = sprintf('  %-8s %s  %s  (%s)', $label, $this->counts($row), $row['test'], $row['job']);
            }
        }
        if (count($lines) > 2) {
            $lines[] = '';
        }

        $lines[] = $this->summary($report);
        $lines = array_merge($lines, $this->jobsWithoutJunit($report), $this->warnings($report));

        return implode("\n", $lines);
    }

    public function renderJson(FlapReport $report): string
    {
        $tests = array_map(static function (array $row): array {
            return [
                'job'     => $row['job'],
                'test'    => $row['test'],
                'class'   => $row['class'],
                'passed'  => $row['green'],
                'failed'  => $row['nongreen'],
                'skipped' => $row['neutral'],
                'absent'  => $row['absent'],
            ];
        }, $report->getTests());

        $payload = [
            'version'          => self::SCHEMA_VERSION,
            'flow'             => $report->getFlow(),
            'repeat'           => $report->getRepeat(),
            'threshold'        => $report->getThreshold(),
            'success'          => $report->isSuccess(),
            'summary'          => $this->summaryCounts($report),
            'tests'            => $tests,
            'jobs'             => $report->getJobs(),
            'anomalies'        => $report->getAnomalies(),
            'collisions'       => $report->getCollisions(),
            'inconclusiveJobs' => $report->getInconclusiveJobs(),
        ];

        return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array{green: int, nongreen: int, neutral: int, absent: int} $row
     */
    private function counts(array $row): string
    {
        $observed = $row['green'] + $row['nongreen'] + $row['neutral'] + $row['absent'];

        return sprintf('%2d/%-2d passed', $row['green'], $observed);
    }

    /**
     * @return array{stable: int, flaky: int, suspect: int, failing: int, alwaysSkipped: int}
     */
    private function summaryCounts(FlapReport $report): array
    {
        $count = static function (string ...$classes) use ($report): int {
            $total = 0;
            foreach ($classes as $class) {
                $total += count($report->getTestsOfClass($class));
            }
            return $total;
        };

        return [
            'stable'          => $count(FlapReport::STABLE_PASS, FlapReport::STABLE_PASS_WITH_SKIPS),
            'flaky'           => $count(FlapReport::FLAKY),
            'suspect'         => $count(FlapReport::SUSPECT),
            'failing'         => $count(FlapReport::STABLE_FAIL, FlapReport::STABLE_FAIL_WITH_SKIPS),
            'alwaysSkipped'   => $count(FlapReport::ALWAYS_SKIPPED),
        ];
    }

    private function summary(FlapReport $report): string
    {
        $counts = $this->summaryCounts($report);

        return sprintf(
            'Summary: %d stable, %d flaky, %d suspect, %d failing, %d always skipped.',
            $counts['stable'],
            $counts['flaky'],
            $counts['suspect'],
            $counts['failing'],
            $counts['alwaysSkipped']
        );
    }

    /**
     * @return string[]
     */
    private function jobsWithoutJunit(FlapReport $report): array
    {
        $lines = [];
        foreach ($report->getJobs() as $job) {
            if ($job['junit'] === null && $job['failed'] > 0) {
                $lines[] = sprintf(
                    "Job '%s' (no junit file) failed in %d of %d passes.",
                    $job['name'],
                    $job['failed'],
                    $report->getRepeat()
                );
            }
        }
        return $lines;
    }

    /**
     * @return string[]
     */
    private function warnings(FlapReport $report): array
    {
        $lines = [];
        foreach ($report->getAnomalies() as $anomaly) {
            $lines[] = sprintf(
                "  - Pass %d, job '%s': junit file %s; the pass is left out of its counts.",
                $anomaly['pass'],
                $anomaly['job'],
                $this->reason($anomaly['reason'])
            );
        }
        foreach ($report->getCollisions() as $collision) {
            $lines[] = sprintf(
                "  - Pass %d, job '%s': test '%s' reported more than once; the worst outcome was kept.",
                $collision['pass'],
                $collision['job'],
                $collision['test']
            );
        }
        foreach ($report->getInconclusiveJobs() as $job) {
            $lines[] = "  - Job '$job': no readable junit file in any pass; nothing was measured.";
        }

        return $lines === [] ? [] : array_merge(['Warnings:'], $lines);
    }

    private function reason(string $fileState): string
    {
        $reasons = [
            JunitReport::FILE_MISSING => 'was not written',
            JunitReport::FILE_CORRUPT => 'could not be parsed',
            JunitReport::FILE_STALE   => 'from the previous pass could not be removed',
        ];

        return $reasons[$fileState] ?? $fileState;
    }
}
