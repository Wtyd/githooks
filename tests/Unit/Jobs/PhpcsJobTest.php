<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Execution\ThreadCapability;
use Wtyd\GitHooks\Jobs\JobRegistry;
use Wtyd\GitHooks\Jobs\PhpcsJob;

/**
 * Direct coverage for PhpcsJob. Infection report 2026-04-20 — L47-50.
 */
class PhpcsJobTest extends TestCase
{
    /** @test */
    public function phpcs_is_a_supported_accelerable_job_type()
    {
        $registry = new JobRegistry();

        $this->assertTrue($registry->isSupported('phpcs'));
        $this->assertTrue($registry->isSupported('phpcbf'));
        $this->assertTrue($registry->isAccelerable('phpcs'));
    }

    /** @test */
    public function default_executable_is_phpcs()
    {
        $this->assertSame('phpcs', PhpcsJob::getDefaultExecutable());
    }

    /** @test */
    public function cache_paths_are_phpcs_cache()
    {
        $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', ['paths' => ['src']]));

        $this->assertSame(['.phpcs.cache'], $job->getCachePaths());
    }

    /** @test */
    public function thread_capability_defaults_to_one_thread_when_parallel_is_absent()
    {
        // Mutants L49: DecrementInteger (1→0) / IncrementInteger (1→2) on the default.
        $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', ['paths' => ['src']]));

        $cap = $job->getThreadCapability();

        $this->assertInstanceOf(ThreadCapability::class, $cap);
        $this->assertSame(1, $cap->getDefaultThreads());
        $this->assertSame('parallel', $cap->getArgumentKey());
    }

    /** @test */
    public function thread_capability_reads_parallel_value_as_integer()
    {
        // Mutant L49 CastInt: `(int) $this->args['parallel']` — string '8' must end up as int 8.
        $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', [
            'paths'    => ['src'],
            'parallel' => '8',
        ]));

        $cap = $job->getThreadCapability();

        $this->assertInstanceOf(ThreadCapability::class, $cap);
        $this->assertSame(8, $cap->getDefaultThreads());
    }

    /** @test */
    public function thread_capability_is_a_new_object_not_null()
    {
        // Mutant L50 NewObject: replacing `new ThreadCapability(...)` with `null`.
        $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', ['paths' => ['src']]));

        $this->assertInstanceOf(ThreadCapability::class, $job->getThreadCapability());
    }

    /** @test */
    public function apply_thread_limit_propagates_value_into_command()
    {
        $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', [
            'executablePath' => 'vendor/bin/phpcs',
            'paths'          => ['src'],
        ]));

        $job->applyThreadLimit(4);

        $this->assertStringContainsString('--parallel=4', $job->buildCommand());
    }

    /** @test */
    public function apply_thread_limit_overrides_existing_parallel_value()
    {
        $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', [
            'executablePath' => 'vendor/bin/phpcs',
            'paths'          => ['src'],
            'parallel'       => 8,
        ]));

        $job->applyThreadLimit(2);

        $command = $job->buildCommand();

        $this->assertStringContainsString('--parallel=2', $command);
        $this->assertStringNotContainsString('--parallel=8', $command);
    }

    /** @test */
    public function supports_structured_output_returns_true()
    {
        $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', ['paths' => ['src']]));

        $this->assertTrue($job->supportsStructuredOutput());
    }

    /** @test */
    public function apply_structured_output_format_sets_report_to_json()
    {
        $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', [
            'executablePath' => 'vendor/bin/phpcs',
            'paths'          => ['src'],
        ]));

        $applied = $job->applyStructuredOutputFormat();

        $this->assertTrue($applied);
        $this->assertStringContainsString('--report=json', $job->buildCommand());
    }

    /** @test */
    public function command_ends_with_paths_without_trailing_whitespace()
    {
        // Guards that buildCommand() doesn't produce trailing spaces when optional
        // fields are absent (protects against PhpmdJob:91 style mutants on siblings).
        $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', [
            'executablePath' => 'vendor/bin/phpcs',
            'paths'          => ['src', 'app'],
        ]));

        $this->assertStringEndsWith('src app', $job->buildCommand());
    }
}
