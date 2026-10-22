<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Flaky;

/**
 * Result of `flow --repeat`: every test seen in a declared junit file with its
 * outcome counts across the passes and its class, plus the per-job and
 * per-pass facts the classification rests on. Immutable.
 *
 * Classes and exit policy: tests/Unit/Flaky/factors.md, table A.
 *
 * @phpstan-type TestRow array{job: string, test: string, class: string, green: int, nongreen: int, neutral: int, absent: int}
 * @phpstan-type JobRow array{name: string, junit: ?string, passed: int, failed: int, skipped: int, readablePasses: int}
 * @phpstan-type Anomaly array{pass: int, job: string, reason: string}
 * @phpstan-type Collision array{pass: int, job: string, test: string}
 */
final class FlapReport
{
    public const STABLE_PASS = 'stable-pass';

    public const STABLE_FAIL = 'stable-fail';

    public const FLAKY = 'flaky';

    public const SUSPECT = 'suspect';

    public const STABLE_PASS_WITH_SKIPS = 'stable-pass-with-skips';

    public const STABLE_FAIL_WITH_SKIPS = 'stable-fail-with-skips';

    public const ALWAYS_SKIPPED = 'always-skipped';

    /** Classes that make the command exit 1. */
    public const FAILING_CLASSES = [self::FLAKY, self::STABLE_FAIL, self::STABLE_FAIL_WITH_SKIPS];

    public const JOB_PASSED = 'passed';

    public const JOB_FAILED = 'failed';

    public const JOB_SKIPPED = 'skipped';

    private string $flow;

    private int $repeat;

    private int $threshold;

    /** @var list<TestRow> */
    private array $tests;

    /** @var list<JobRow> */
    private array $jobs;

    /** @var list<Anomaly> */
    private array $anomalies;

    /** @var list<Collision> */
    private array $collisions;

    /**
     * @param list<TestRow> $tests
     * @param list<JobRow> $jobs
     * @param list<Anomaly> $anomalies
     * @param list<Collision> $collisions
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Value object built once by FlapAggregator.
     */
    public function __construct(
        string $flow,
        int $repeat,
        int $threshold,
        array $tests,
        array $jobs,
        array $anomalies,
        array $collisions
    ) {
        $this->flow = $flow;
        $this->repeat = $repeat;
        $this->threshold = $threshold;
        $this->tests = $tests;
        $this->jobs = $jobs;
        $this->anomalies = $anomalies;
        $this->collisions = $collisions;
    }

    public function getFlow(): string
    {
        return $this->flow;
    }

    public function getRepeat(): int
    {
        return $this->repeat;
    }

    public function getThreshold(): int
    {
        return $this->threshold;
    }

    /**
     * @return list<TestRow>
     */
    public function getTests(): array
    {
        return $this->tests;
    }

    /**
     * @return list<TestRow>
     */
    public function getTestsOfClass(string $class): array
    {
        return array_values(array_filter($this->tests, static function (array $row) use ($class): bool {
            return $row['class'] === $class;
        }));
    }

    /**
     * @return list<JobRow>
     */
    public function getJobs(): array
    {
        return $this->jobs;
    }

    /**
     * @return list<Anomaly>
     */
    public function getAnomalies(): array
    {
        return $this->anomalies;
    }

    /**
     * @return list<Collision>
     */
    public function getCollisions(): array
    {
        return $this->collisions;
    }

    /**
     * Jobs that declare a junit file but produced no readable one in any pass:
     * nothing was measured, so their tests cannot be called stable.
     *
     * @return string[]
     */
    public function getInconclusiveJobs(): array
    {
        $names = [];
        foreach ($this->jobs as $job) {
            if ($job['junit'] !== null && $job['readablePasses'] === 0) {
                $names[] = $job['name'];
            }
        }
        return $names;
    }

    public function isSuccess(): bool
    {
        foreach ($this->tests as $row) {
            if (in_array($row['class'], self::FAILING_CLASSES, true)) {
                return false;
            }
        }

        return $this->getInconclusiveJobs() === [];
    }

    public function exitCode(): int
    {
        return $this->isSuccess() ? 0 : 1;
    }
}
