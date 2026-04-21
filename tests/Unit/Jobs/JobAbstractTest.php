<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Jobs\ParallelLintJob;
use Wtyd\GitHooks\Jobs\PhpstanJob;

/**
 * Direct coverage for JobAbstract logic that was only exercised indirectly.
 * Infection report 2026-04-20 — L187 CastArray on 'repeat', L216 Coalesce on paths.
 */
class JobAbstractTest extends TestCase
{
    /** @test */
    public function repeat_type_wraps_scalar_value_into_single_iteration()
    {
        // Mutant L187: removing `(array)` cast → foreach on scalar produces empty output.
        // Passing a scalar (not an array) to a repeat-type argument must still produce one flag.
        $job = new ParallelLintJob(new JobConfiguration('lint', 'parallel-lint', [
            'exclude' => 'vendor',
            'paths'   => ['./'],
        ]));

        $command = $job->buildCommand();

        $this->assertStringContainsString('--exclude vendor', $command);
        $this->assertStringEndsWith('./', $command);
    }

    /** @test */
    public function repeat_type_produces_one_flag_per_array_item()
    {
        $job = new ParallelLintJob(new JobConfiguration('lint', 'parallel-lint', [
            'exclude' => ['vendor', 'tools', 'node_modules'],
            'paths'   => ['./'],
        ]));

        $command = $job->buildCommand();

        $this->assertSame(3, substr_count($command, '--exclude '));
        $this->assertStringContainsString('--exclude vendor', $command);
        $this->assertStringContainsString('--exclude tools', $command);
        $this->assertStringContainsString('--exclude node_modules', $command);
    }

    /** @test */
    public function configured_paths_returns_explicit_array()
    {
        // Mutant L216: swapping `$this->args['paths'] ?? []` → always returns [].
        $job = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', [
            'paths' => ['src', 'app'],
        ]));

        $this->assertSame(['src', 'app'], $job->getConfiguredPaths());
    }

    /** @test */
    public function configured_paths_returns_empty_array_when_not_set()
    {
        $job = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', []));

        $this->assertSame([], $job->getConfiguredPaths());
    }

    /** @test */
    public function is_empty_guard_keeps_boolean_false_flags_off_and_boolean_true_flags_on()
    {
        // Mutants around the isEmpty() guard and the empty() call:
        // `empty(false)` is true, so without the `is_bool` short-circuit the `no-progress => false` arg
        // would be kept or dropped inconsistently depending on the mutation. The boolean branch must
        // stay in sync: false → flag absent, true → flag present.
        $jobFalse = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', [
            'no-progress' => false,
            'paths'       => ['src'],
        ]));
        $jobTrue = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', [
            'no-progress' => true,
            'paths'       => ['src'],
        ]));

        $this->assertStringNotContainsString('--no-progress', $jobFalse->buildCommand());
        $this->assertStringContainsString('--no-progress', $jobTrue->buildCommand());
    }

    /** @test */
    public function name_type_and_display_name_are_exposed_verbatim()
    {
        $job = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', ['paths' => ['src']]));

        $this->assertSame('phpstan_src', $job->getName());
        $this->assertSame('phpstan', $job->getType());
        $this->assertSame('phpstan_src', $job->getDisplayName());
    }

    /** @test */
    public function ignore_errors_and_fail_fast_default_to_false()
    {
        $job = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', ['paths' => ['src']]));

        $this->assertFalse($job->isIgnoreErrorsOnExit());
        $this->assertFalse($job->isFailFast());
    }

    /** @test */
    public function executable_prefix_joins_with_single_space_before_executable()
    {
        $job = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', [
            'executablePath' => 'vendor/bin/phpstan',
            'paths'          => ['src'],
        ]));
        $job->applyExecutablePrefix('docker exec -i app');

        $this->assertStringStartsWith('docker exec -i app vendor/bin/phpstan analyse ', $job->buildCommand());
    }

    /** @test */
    public function cli_extra_arguments_append_with_leading_space()
    {
        $job = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', [
            'executablePath' => 'vendor/bin/phpstan',
            'paths'          => ['src'],
        ]));
        $job->applyCliExtraArguments('--memory-limit=2G');

        $command = $job->buildCommand();

        $this->assertStringContainsString(' --memory-limit=2G ', $command . ' ');
        $this->assertStringEndsWith('src', $command);
    }

    /** @test */
    public function get_cache_paths_defaults_to_empty_on_base_class()
    {
        // PhpcpdJob inherits JobAbstract::getCachePaths() unchanged.
        $job = new \Wtyd\GitHooks\Jobs\PhpcpdJob(new JobConfiguration('cpd', 'phpcpd', ['paths' => ['./']]));

        $this->assertSame([], $job->getCachePaths());
    }

    /** @test */
    public function get_thread_capability_defaults_to_null_on_base_class()
    {
        // ScriptJob inherits JobAbstract::getThreadCapability() default (null).
        $job = new \Wtyd\GitHooks\Jobs\ScriptJob(new JobConfiguration('x', 'script', ['executablePath' => 'echo']));

        $this->assertNull($job->getThreadCapability());
    }

    /** @test */
    public function supports_structured_output_defaults_to_false_on_base_class()
    {
        $job = new \Wtyd\GitHooks\Jobs\ScriptJob(new JobConfiguration('x', 'script', ['executablePath' => 'echo']));

        $this->assertFalse($job->supportsStructuredOutput());
        $this->assertFalse($job->applyStructuredOutputFormat());
    }

    /** @test */
    public function is_fix_applied_defaults_to_false_on_base_class()
    {
        $job = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', ['paths' => ['src']]));

        $this->assertFalse($job->isFixApplied(0));
        $this->assertFalse($job->isFixApplied(1));
    }
}
