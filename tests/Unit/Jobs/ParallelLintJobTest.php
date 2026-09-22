<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Execution\ThreadCapability;
use Wtyd\GitHooks\Jobs\ParallelLintJob;

/**
 * Direct coverage of ParallelLintJob: the thread capability it declares to
 * the budget allocator and the structured-output switch. The job had no test
 * file of its own, so the `-j` default was only ever exercised through jobs
 * that already declared `jobs` explicitly.
 */
class ParallelLintJobTest extends UnitTestCase
{
    /**
     * @param array<string, mixed> $args
     */
    private function job(array $args): ParallelLintJob
    {
        return new ParallelLintJob(new JobConfiguration('lint', 'parallel-lint', $args));
    }

    /**
     * @test
     * @dataProvider jobsCases
     *
     * Both sides of the `?? 10` fallback. parallel-lint's own default is 10
     * workers, and the allocator subtracts that number from the budget, so
     * the value is part of the contract — asserting only the argument key
     * lets it drift silently.
     *
     * @param array<string, mixed> $args
     */
    public function thread_capability_reports_the_declared_jobs_or_the_tool_default(array $args, int $expected)
    {
        $capability = $this->job($args)->getThreadCapability();

        $this->assertInstanceOf(ThreadCapability::class, $capability);
        $this->assertSame('jobs', $capability->getArgumentKey());
        $this->assertSame($expected, $capability->getDefaultThreads());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: int}>
     */
    public function jobsCases(): array
    {
        return [
            'jobs declared'          => [['paths' => ['src'], 'jobs' => 4], 4],
            'jobs declared as string' => [['paths' => ['src'], 'jobs' => '6'], 6],
            'jobs absent'            => [['paths' => ['src']], 10],
        ];
    }

    /** @test */
    public function apply_thread_limit_overwrites_the_jobs_argument()
    {
        $job = $this->job(['paths' => ['src'], 'jobs' => 10]);

        $job->applyThreadLimit(2);

        $this->assertSame(2, $job->getThreadCapability()->getDefaultThreads());
    }

    /** @test */
    public function structured_output_switches_the_json_flag_on()
    {
        $job = $this->job(['paths' => ['src']]);

        $this->assertTrue($job->supportsStructuredOutput());
        $this->assertTrue($job->applyStructuredOutputFormat());
        $this->assertStringContainsString('--json', $job->buildCommand());
    }

    /** @test */
    public function the_default_executable_is_the_tool_name()
    {
        $this->assertSame('parallel-lint', ParallelLintJob::getDefaultExecutable());
    }
}
