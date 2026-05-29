<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Jobs\CustomJob;
use Wtyd\GitHooks\Jobs\ParallelLintJob;
use Wtyd\GitHooks\Jobs\ParatestJob;
use Wtyd\GitHooks\Jobs\PhpcbfJob;
use Wtyd\GitHooks\Jobs\PhpcpdJob;
use Wtyd\GitHooks\Jobs\PhpCsFixerJob;
use Wtyd\GitHooks\Jobs\PhpcsJob;
use Wtyd\GitHooks\Jobs\PhpmdJob;
use Wtyd\GitHooks\Jobs\PhpstanJob;
use Wtyd\GitHooks\Jobs\PhpunitJob;
use Wtyd\GitHooks\Jobs\PsalmJob;
use Wtyd\GitHooks\Jobs\RectorJob;
use Wtyd\GitHooks\Jobs\ScriptJob;

/**
 * Direct coverage for JobAbstract logic that was only exercised indirectly.
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

    /**
     * BUG-23 regression net: `getDisplayName()` must return the job key for every Job type,
     * not the executable. A `script`-typed override historically returned `$this->executable`,
     * making two parallel jobs with the same executable indistinguishable in OK/KO/SKIP logs.
     * Parametrized so adding a new Job type without inheriting the parent behaviour breaks here.
     *
     * @test
     * @dataProvider allJobClassesProvider
     */
    public function display_name_returns_job_key_for_every_job_type(
        string $jobClass,
        string $jobType
    ): void {
        $config = new JobConfiguration(
            "{$jobType}_shard_a",
            $jobType,
            [
                'executable-path' => './run-tests',
                'script'          => 'true',
                'paths'           => ['src'],
            ]
        );

        $job = new $jobClass($config);

        $this->assertSame("{$jobType}_shard_a", $job->getDisplayName());
    }

    /** @return array<string, array{0: class-string, 1: string}> */
    public static function allJobClassesProvider(): array
    {
        return [
            'phpstan'       => [PhpstanJob::class, 'phpstan'],
            'phpmd'         => [PhpmdJob::class, 'phpmd'],
            'phpcs'         => [PhpcsJob::class, 'phpcs'],
            'phpcbf'        => [PhpcbfJob::class, 'phpcbf'],
            'phpunit'       => [PhpunitJob::class, 'phpunit'],
            'paratest'      => [ParatestJob::class, 'paratest'],
            'psalm'         => [PsalmJob::class, 'psalm'],
            'parallel-lint' => [ParallelLintJob::class, 'parallel-lint'],
            'phpcpd'        => [PhpcpdJob::class, 'phpcpd'],
            'php-cs-fixer'  => [PhpCsFixerJob::class, 'php-cs-fixer'],
            'rector'        => [RectorJob::class, 'rector'],
            'script'        => [ScriptJob::class, 'script'],
            'custom'        => [CustomJob::class, 'custom'],
        ];
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
            'executable-path' => 'vendor/bin/phpstan',
            'paths'          => ['src'],
        ]));
        $job->applyExecutablePrefix('docker exec -i app');

        $this->assertStringStartsWith('docker exec -i app vendor/bin/phpstan analyse ', $job->buildCommand());
    }

    /** @test */
    public function cli_extra_arguments_append_with_leading_space()
    {
        $job = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', [
            'executable-path' => 'vendor/bin/phpstan',
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
        $job = new \Wtyd\GitHooks\Jobs\ScriptJob(new JobConfiguration('x', 'script', ['executable-path' => 'echo']));

        $this->assertNull($job->getThreadCapability());
    }

    /** @test */
    public function supports_structured_output_defaults_to_false_on_base_class()
    {
        $job = new \Wtyd\GitHooks\Jobs\ScriptJob(new JobConfiguration('x', 'script', ['executable-path' => 'echo']));

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


    /** @test */
    public function extract_positive_int_returns_null_for_string_value()
    {
        // Kills LogicalAnd `&&` -> `||` mutant on
        // `is_int($value) && $value >= 1` at line 92. With `||`, a
        // string like '5' would pass `>= 1` (PHP coercion) and return
        // the string — but the function is typed `?int`, so under
        // strict_types it would TypeError.
        $job = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', [
            'paths' => ['src'],
            'warn-after' => '5',
        ]));

        $this->assertNull($job->getWarnAfter());
    }

    /** @test */
    public function extract_positive_int_accepts_value_at_minimum_boundary()
    {
        // Pin the boundary `>= 1` (line 92): exactly 1 must be accepted.
        $job = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', [
            'paths' => ['src'],
            'warn-after' => 1,
        ]));

        $this->assertSame(1, $job->getWarnAfter());
    }

    /** @test */
    public function extract_cores_override_returns_null_for_string_value()
    {
        // Kills LogicalAnd `&&` -> `||` mutant on
        // `is_int($cores) && $cores >= 1` at line 106.
        $job = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', [
            'paths' => ['src'],
            'cores' => '4',
        ]));

        $this->assertNull($job->getCoresOverride());
    }

    /** @test */
    public function extract_cores_override_accepts_value_at_minimum_boundary()
    {
        // Kills GreaterThanOrEqualTo `>=` -> `>` boundary at line 106.
        $job = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', [
            'paths' => ['src'],
            'cores' => 1,
        ]));

        $this->assertSame(1, $job->getCoresOverride());
    }

    /**
     * @test
     * Kills JobAbstract:292 ConcatOperandRemoval (`static::class . '::SUPPORTS_FAST'`
     * → `static::class`) and JobAbstract:293 LogicalAndSingleSubExprNegation
     * (`defined($constant) && (bool) constant($constant)` → `!defined($constant)
     * && (bool) constant($constant)`).
     *
     * The instance method `JobAbstract::isAccelerable()` is otherwise covered
     * only via JobRegistry, which uses a different code path
     * (`defined("$class::SUPPORTS_FAST") && $class::SUPPORTS_FAST`) and never
     * triggers the runtime concat / late-static binding inside the abstract.
     *
     * With M292 the constant name becomes the class FQN, defined() returns
     * false (classes aren't constants), and isAccelerable returns false even
     * for a SUPPORTS_FAST=true subclass. With M293 the negation flips the
     * defined() guard to true only when the constant is MISSING — also
     * dropping the result to false for SUPPORTS_FAST=true subclasses.
     */
    public function isAccelerable_resolves_supports_fast_constant_on_subclass()
    {
        $accelerable = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', [
            'paths' => ['src'],
        ]));
        $nonAccelerable = new CustomJob(new JobConfiguration('script_only', 'custom', [
            'script' => 'echo hi',
        ]));

        $this->assertTrue(
            $accelerable->isAccelerable(),
            'PhpstanJob::SUPPORTS_FAST=true must surface through isAccelerable()'
        );
        $this->assertFalse(
            $nonAccelerable->isAccelerable(),
            'CustomJob::SUPPORTS_FAST=false must surface as not accelerable'
        );
    }

    /**
     * @test
     * Kills the explicit `accelerable` argument override path. The args-based
     * branch short-circuits the constant lookup, so the test must drive both
     * directions on a class whose SUPPORTS_FAST const says the OPPOSITE — the
     * arg has to actually win.
     */
    public function isAccelerable_explicit_arg_overrides_supports_fast_constant()
    {
        $forcedOff = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', [
            'paths'       => ['src'],
            'accelerable' => false,
        ]));
        $forcedOn = new CustomJob(new JobConfiguration('forced', 'custom', [
            'script'      => 'echo hi',
            'accelerable' => true,
        ]));

        $this->assertFalse($forcedOff->isAccelerable(), 'accelerable=false must override SUPPORTS_FAST=true');
        $this->assertTrue($forcedOn->isAccelerable(), 'accelerable=true must override SUPPORTS_FAST=false');
    }

    /** @test */
    public function getCoresOverride_promotes_phpcs_parallel_when_cores_absent()
    {
        $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', [
            'paths'    => ['src'],
            'parallel' => 4,
        ]));

        $this->assertSame(4, $job->getCoresOverride());
    }

    /** @test */
    public function getCoresOverride_promotes_phpcbf_parallel_through_inheritance()
    {
        // PhpcbfJob extends PhpcsJob and inherits the controllable
        // 'parallel' capability. The promotion path must reach phpcbf
        // by inheritance — pinning this guards against a future override
        // of getThreadCapability in PhpcbfJob that breaks the symmetry.
        $job = new PhpcbfJob(new JobConfiguration('phpcbf_src', 'phpcbf', [
            'paths'    => ['src'],
            'parallel' => 5,
        ]));

        $this->assertSame(5, $job->getCoresOverride());
    }

    /** @test */
    public function getCoresOverride_promotes_psalm_threads_when_cores_absent()
    {
        $job = new PsalmJob(new JobConfiguration('psalm_src', 'psalm', [
            'paths'   => ['src'],
            'threads' => 3,
        ]));

        $this->assertSame(3, $job->getCoresOverride());
    }

    /** @test */
    public function getCoresOverride_promotes_parallel_lint_jobs_when_cores_absent()
    {
        $job = new ParallelLintJob(new JobConfiguration('lint', 'parallel-lint', [
            'paths' => ['./'],
            'jobs'  => 6,
        ]));

        $this->assertSame(6, $job->getCoresOverride());
    }

    /** @test */
    public function getCoresOverride_promotes_paratest_processes_when_cores_absent()
    {
        $job = new ParatestJob(new JobConfiguration('paratest', 'paratest', [
            'processes' => 8,
        ]));

        $this->assertSame(8, $job->getCoresOverride());
    }

    /** @test */
    public function getCoresOverride_returns_explicit_cores_when_both_native_and_cores_set()
    {
        // cores wins over the native flag — already true; this test pins the
        // contract so the new "promote native" path doesn't accidentally
        // overwrite the explicit cores value.
        $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', [
            'paths'    => ['src'],
            'parallel' => 4,
            'cores'    => 2,
        ]));

        $this->assertSame(2, $job->getCoresOverride());
    }

    /** @test */
    public function getCoresOverride_returns_null_when_native_flag_value_is_invalid()
    {
        // The promoted value must pass the same is_int && >=1 guard as
        // explicit cores. A string like '4' is rejected.
        $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', [
            'paths'    => ['src'],
            'parallel' => '4',
        ]));

        $this->assertNull($job->getCoresOverride());
    }

    /** @test */
    public function getCoresOverride_does_not_promote_for_uncontrollable_tools()
    {
        // phpstan has a ThreadCapability but it is uncontrollable
        // (workers come from .neon, no CLI flag). No native flag exists in
        // its ARGUMENT_MAP, so there is nothing to promote — the override
        // stays null when 'cores' is absent.
        $job = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', [
            'paths'  => ['src'],
            'config' => 'qa/phpstan.neon',
        ]));

        $this->assertNull($job->getCoresOverride());
    }

    /** @test */
    public function getCoresOverride_does_not_promote_for_single_threaded_tools()
    {
        // phpmd has no ThreadCapability at all. Nothing to promote.
        $job = new PhpmdJob(new JobConfiguration('phpmd_src', 'phpmd', [
            'paths' => ['src'],
            'rules' => 'cleancode',
        ]));

        $this->assertNull($job->getCoresOverride());
    }

    /** @test */
    public function getCoresOverride_does_not_promote_for_custom_jobs()
    {
        // CustomJob has no ThreadCapability by default. The user must declare
        // 'cores' explicitly if they want budget reservation.
        $job = new CustomJob(new JobConfiguration('eslint', 'custom', [
            'script' => 'npx eslint src/',
        ]));

        $this->assertNull($job->getCoresOverride());
    }

    /**
     * @test
     *
     * Boundary: native thread arg = 1 must be promoted to a coresOverride
     * of 1 (the minimum positive integer). The mutant `> 1` would reject
     * 1 and return null, leaving the budget allocator without an override
     * for jobs that DO declare parallel:1 explicitly.
     */
    public function getCoresOverride_accepts_value_one_at_boundary(): void
    {
        $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', [
            'paths'    => ['src'],
            'parallel' => 1,
        ]));

        $this->assertSame(1, $job->getCoresOverride(), 'value=1 must be a valid override');
    }

    /**
     * Companion: value=0 must STILL be rejected (no override) — kills the
     * other side of the boundary (`>= 1` ⇒ 0 rejected).
     *
     * @test
     */
    public function getCoresOverride_rejects_value_zero_at_boundary(): void
    {
        $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', [
            'paths'    => ['src'],
            'parallel' => 0,
        ]));

        $this->assertNull($job->getCoresOverride(), 'value=0 must NOT be a valid override');
    }

    /**
     * @test
     *
     * In strict_types mode, the return type `bool` rejects a string return
     * value with TypeError. The cast guarantees the args value (which the
     * configuration loader may not have coerced) is always returned as a
     * bool. Drive with a non-bool truthy string.
     */
    public function isAccelerable_casts_non_bool_args_value_to_bool(): void
    {
        $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', [
            'paths'       => ['src'],
            'accelerable' => 'yes', // non-bool truthy
        ]));

        $this->assertTrue($job->isAccelerable(), 'truthy non-bool must return true via cast');

        $jobFalsy = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', [
            'paths'       => ['src'],
            'accelerable' => 0, // non-bool falsy
        ]));

        $this->assertFalse($jobFalsy->isAccelerable(), 'falsy non-bool must return false via cast');
    }

    /**
     * @test
     *
     * The isEmpty() helper has a special-case contract: booleans are NEVER
     * empty (false is a valid, intentional flag value). With IfNegation,
     * a non-bool empty value (e.g. '') would short-circuit to `return false`
     * instead of falling through to empty(). With ReturnRemoval, a literal
     * `false` would fall through to empty(false)=true and be misclassified
     * as empty.
     */
    public function isEmpty_treats_booleans_as_never_empty(): void
    {
        // Use any JobAbstract subclass to access the private isEmpty via Reflection.
        $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', ['paths' => ['src']]));
        $ref = new \ReflectionMethod($job, 'isEmpty');
        $ref->setAccessible(true);

        // booleans → never empty (kills ReturnRemoval).
        $this->assertFalse($ref->invoke($job, false), 'literal false must NOT be classified as empty');
        $this->assertFalse($ref->invoke($job, true), 'literal true must NOT be classified as empty');

        // Empty string → IS empty (kills IfNegation: with the negated guard,
        // a non-bool empty value would erroneously short-circuit to `false`).
        $this->assertTrue($ref->invoke($job, ''), 'empty string must be classified as empty');
        $this->assertTrue($ref->invoke($job, []), 'empty array must be classified as empty');

        // Non-empty non-bool → not empty.
        $this->assertFalse($ref->invoke($job, 'value'), 'non-empty string is not empty');
    }
}
