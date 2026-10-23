<?php

declare(strict_types=1);

namespace Wtyd\Alfil\Flaky;

/**
 * Crosses the junit files of N passes test by test and classifies every test
 * (tests/Unit/Flaky/factors.md, table A).
 *
 * A pass whose junit file could not be read is an anomaly of that job: it is
 * left out of the counts of every test of the job, never read as "passed".
 */
final class FlapAggregator
{
    private int $threshold;

    public function __construct(int $threshold)
    {
        $this->threshold = max(1, $threshold);
    }

    /**
     * @param array<string, string> $junitPaths job name => junit path as declared
     * @param list<array{reports: array<string, JunitReport>, jobs: array<string, string>}> $passes
     *   per pass: the junit report of each job in $junitPaths, and the outcome
     *   (FlapReport::JOB_*) of every job that took part in the pass
     */
    public function aggregate(string $flow, array $junitPaths, array $passes): FlapReport
    {
        $tests = [];
        $anomalies = [];
        $collisions = [];

        foreach (array_keys($junitPaths) as $job) {
            $readable = [];
            foreach ($passes as $index => $pass) {
                $report = $pass['reports'][$job] ?? JunitReport::missing();
                if (!$report->isReadable()) {
                    $anomalies[] = ['pass' => $index + 1, 'job' => $job, 'reason' => $report->getFileState()];
                    continue;
                }
                foreach ($report->getCollisions() as $test) {
                    $collisions[] = ['pass' => $index + 1, 'job' => $job, 'test' => $test];
                }
                $readable[] = $report;
            }
            $tests = array_merge($tests, $this->classifyJob($job, $readable));
        }

        return new FlapReport(
            $flow,
            count($passes),
            $this->threshold,
            $tests,
            $this->jobRows($junitPaths, $passes, $anomalies),
            $anomalies,
            $collisions
        );
    }

    /**
     * @param JunitReport[] $readable the passes of this job whose junit could be read
     * @return list<array{job: string, test: string, class: string, green: int, nongreen: int, neutral: int, absent: int}>
     */
    private function classifyJob(string $job, array $readable): array
    {
        $counts = [];
        foreach ($readable as $report) {
            foreach ($report->getTests() as $test => $status) {
                $counts[$test] = $counts[$test] ?? [TestStatus::GREEN => 0, TestStatus::NONGREEN => 0, TestStatus::NEUTRAL => 0];
                $counts[$test][$status]++;
            }
        }

        $rows = [];
        foreach ($counts as $test => $count) {
            $green = $count[TestStatus::GREEN];
            $nongreen = $count[TestStatus::NONGREEN];
            $neutral = $count[TestStatus::NEUTRAL];
            $absent = count($readable) - $green - $nongreen - $neutral;
            $rows[] = [
                'job'      => $job,
                'test'     => (string) $test,
                'class'    => $this->classify($green, $nongreen, $neutral, $absent),
                'green'    => $green,
                'nongreen' => $nongreen,
                'neutral'  => $neutral,
                'absent'   => $absent,
            ];
        }
        return $rows;
    }

    /**
     * Mixed outcomes decide first: a test that went both ways is flaky (or
     * suspect, below the threshold) whatever skips or absences sit beside.
     */
    private function classify(int $green, int $nongreen, int $neutral, int $absent): string
    {
        if ($green > 0 && $nongreen > 0) {
            return min($green, $nongreen) >= $this->threshold ? FlapReport::FLAKY : FlapReport::SUSPECT;
        }

        return $this->classifyOneSided($green, $nongreen, $neutral, $absent);
    }

    /**
     * Tests that never went both ways: stable (pass or fail), noted when some
     * passes skipped them or did not run them, or never executed at all. A
     * test only exists here because some pass reported it, so with neither a
     * pass nor a failure it was skipped at least once.
     */
    private function classifyOneSided(int $green, int $nongreen, int $neutral, int $absent): string
    {
        $noted = $neutral > 0 || $absent > 0;

        if ($nongreen > 0) {
            return $noted ? FlapReport::STABLE_FAIL_WITH_SKIPS : FlapReport::STABLE_FAIL;
        }
        if ($green > 0) {
            return $noted ? FlapReport::STABLE_PASS_WITH_SKIPS : FlapReport::STABLE_PASS;
        }

        return FlapReport::ALWAYS_SKIPPED;
    }

    /**
     * Job-level counts for every job that took part, with or without junit:
     * a job that fails in every pass must not vanish just because it declared
     * no junit file.
     *
     * @param array<string, string> $junitPaths
     * @param list<array{reports: array<string, JunitReport>, jobs: array<string, string>}> $passes
     * @param list<array{pass: int, job: string, reason: string}> $anomalies
     * @return list<array{name: string, junit: ?string, passed: int, failed: int, skipped: int, readablePasses: int}>
     */
    private function jobRows(array $junitPaths, array $passes, array $anomalies): array
    {
        $outcomes = [];
        foreach ($passes as $pass) {
            foreach ($pass['jobs'] as $name => $outcome) {
                $outcomes[(string) $name][] = $outcome;
            }
        }
        $unreadable = array_count_values(array_column($anomalies, 'job'));

        $names = array_unique(array_merge(array_keys($outcomes), array_map('strval', array_keys($junitPaths))));
        $rows = [];
        foreach ($names as $name) {
            $name = (string) $name;
            $counts = array_count_values($outcomes[$name] ?? []);
            $rows[] = [
                'name'           => $name,
                'junit'          => $junitPaths[$name] ?? null,
                'passed'         => $counts[FlapReport::JOB_PASSED] ?? 0,
                'failed'         => $counts[FlapReport::JOB_FAILED] ?? 0,
                'skipped'        => $counts[FlapReport::JOB_SKIPPED] ?? 0,
                'readablePasses' => isset($junitPaths[$name]) ? count($passes) - ($unreadable[$name] ?? 0) : 0,
            ];
        }
        return $rows;
    }
}
