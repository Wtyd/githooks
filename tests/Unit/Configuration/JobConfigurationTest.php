<?php

declare(strict_types=1);

namespace Tests\Unit\Configuration;

use PHPUnit\Framework\TestCase;
use Tests\Support\AssertWarningsTrait;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Configuration\ValidationResult;
use Wtyd\GitHooks\Execution\ExecutionMode;
use Wtyd\GitHooks\Jobs\JobRegistry;
use Wtyd\GitHooks\Registry\ToolRegistry;

class JobConfigurationTest extends TestCase
{
    use AssertWarningsTrait;

    private ToolRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ToolRegistry();
    }

    /** @test */
    public function it_parses_a_valid_job()
    {
        $result = new ValidationResult();
        $job = JobConfiguration::fromArray('phpstan_src', [
            'type'   => 'phpstan',
            'paths'  => ['src'],
            'config' => 'qa/phpstan.neon',
        ], $this->registry, $result);

        $this->assertFalse($result->hasErrors());
        $this->assertNotNull($job);
        $this->assertEquals('phpstan_src', $job->getName());
        $this->assertEquals('phpstan', $job->getType());
        $this->assertEquals(['src'], $job->getPaths());
        $this->assertArrayHasKey('config', $job->getConfig());
        $this->assertArrayNotHasKey('type', $job->getConfig());
    }

    /** @test */
    public function it_parses_a_custom_job()
    {
        $result = new ValidationResult();
        $job = JobConfiguration::fromArray('lint_js', [
            'type'   => 'custom',
            'script' => 'npm run lint',
        ], $this->registry, $result);

        $this->assertFalse($result->hasErrors());
        $this->assertNotNull($job);
        $this->assertEquals('custom', $job->getType());
    }

    /** @test */
    public function it_reports_error_when_type_is_missing()
    {
        $result = new ValidationResult();
        $job = JobConfiguration::fromArray('bad_job', ['paths' => ['src']], $this->registry, $result);

        $this->assertNull($job);
        $this->assertTrue($result->hasErrors());
        $this->assertStringContainsString("missing the required 'type'", $result->getErrors()[0]);
    }

    /** @test */
    public function it_reports_error_for_unsupported_type()
    {
        $result = new ValidationResult();
        $job = JobConfiguration::fromArray('bad_job', ['type' => 'nonexistent'], $this->registry, $result);

        $this->assertNull($job);
        $this->assertTrue($result->hasErrors());
        $this->assertStringContainsString('not a supported tool', $result->getErrors()[0]);
    }

    /** @test */
    public function it_reports_error_for_custom_without_script()
    {
        $result = new ValidationResult();
        $job = JobConfiguration::fromArray('bad_custom', ['type' => 'custom'], $this->registry, $result);

        $this->assertNull($job);
        $this->assertTrue($result->hasErrors());
        $this->assertStringContainsString("'script' or 'executable-path' key", $result->getErrors()[0]);
    }

    /** @test */
    public function it_warns_when_value_arg_is_not_string_or_int()
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('test', [
            'type' => 'phpstan',
            'config' => ['should', 'be', 'string'],
        ], $this->registry, $result, new JobRegistry());

        $warnings = $result->getWarnings();
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('config', $warnings[0]);
    }

    /** @test */
    public function it_warns_when_boolean_arg_is_not_bool()
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('test', [
            'type' => 'phpstan',
            'no-progress' => 'yes',
        ], $this->registry, $result, new JobRegistry());

        $warnings = $result->getWarnings();
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('no-progress', $warnings[0]);
    }

    /** @test */
    public function it_warns_when_paths_is_not_array()
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('test', [
            'type' => 'phpcs',
            'paths' => 'src',
        ], $this->registry, $result, new JobRegistry());

        $warnings = $result->getWarnings();
        $found = false;
        foreach ($warnings as $w) {
            if (strpos($w, 'paths') !== false && strpos($w, 'array') !== false) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'Expected warning about paths not being an array');
    }

    /**
     * @test
     * Kills L138 LogicalAnd→Or: the guard `!is_array($value) && !is_string($value)`
     * flipped to `||` would warn for valid array values. Exact-string match on
     * an integer input plus the "no warning" test below cover both sides.
     */
    public function it_warns_with_exact_message_when_csv_arg_is_integer()
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('test', [
            'type'   => 'phpcs',
            'ignore' => 123,
        ], $this->registry, $result, new JobRegistry());

        $this->assertWarningEquals("Job 'test': key 'ignore' expects an array or string.", $result);
    }

    /** @test */
    public function it_does_not_warn_when_csv_arg_is_a_valid_array()
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('test', [
            'type'   => 'phpcs',
            'ignore' => ['vendor', 'tools'],
        ], $this->registry, $result, new JobRegistry());

        $this->assertEmpty(array_filter($result->getWarnings(), function (string $w) {
            return strpos($w, "'ignore'") !== false;
        }));
    }

    /** @test */
    public function it_does_not_warn_when_csv_arg_is_a_valid_string()
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('test', [
            'type'   => 'phpcs',
            'ignore' => 'vendor,tools',
        ], $this->registry, $result, new JobRegistry());

        $this->assertEmpty(array_filter($result->getWarnings(), function (string $w) {
            return strpos($w, "'ignore'") !== false;
        }));
    }

    /** @test */
    public function it_does_not_warn_for_valid_typed_arguments()
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('test', [
            'type' => 'phpstan',
            'config' => 'phpstan.neon',
            'level' => 8,
            'no-progress' => true,
            'paths' => ['src'],
        ], $this->registry, $result, new JobRegistry());

        $this->assertEmpty($result->getWarnings());
        $this->assertFalse($result->hasErrors());
    }

    /**
     * @test
     * Kills L111 Foreach_→[] in validateArguments: the loop that warns about
     * unknown keys on tool-typed jobs (non-custom) must iterate the config.
     * Emptying the loop would silently accept arbitrary typos.
     */
    public function it_warns_about_unknown_keys_for_tool_typed_jobs()
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('phpstan_src', [
            'type'         => 'phpstan',
            'paths'        => ['src'],
            'unknown_foo'  => 'x',
            'another_bad'  => 42,
        ], $this->registry, $result, new JobRegistry());

        $this->assertWarningEquals("Job 'phpstan_src': unknown key 'unknown_foo' for type 'phpstan'.", $result);
        $this->assertWarningEquals("Job 'phpstan_src': unknown key 'another_bad' for type 'phpstan'.", $result);
    }

    /** @test */
    public function it_warns_when_custom_job_has_unknown_keys()
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('audit', [
            'type' => 'custom',
            'script' => 'echo ok',
            'inventado' => true,
            'otra clave' => 'valor',
        ], $this->registry, $result);

        $this->assertCount(2, $result->getWarnings());
        $this->assertStringContainsString('inventado', $result->getWarnings()[0]);
        $this->assertStringContainsString('otra clave', $result->getWarnings()[1]);
    }

    /**
     * @test
     * Anchors CON-007 of spec-design-files-flag.md (revised by BUG-9):
     * declaring `files`, `files-from` or `exclude-pattern` as a static job
     * key is a hard error, not a warning. The flags are exclusively CLI
     * and baking volatile input into a job is treated as a misconfiguration.
     */
    public function it_errors_when_job_declares_cli_only_files_keys_in_config()
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('phpstan_src', [
            'type'        => 'phpstan',
            'paths'       => ['src'],
            'files'       => ['src/X.php'],
            'files-from'  => 'changed.txt',
        ], $this->registry, $result, new JobRegistry());

        $this->assertTrue($result->hasErrors());
        $errors = implode("\n", $result->getErrors());
        $this->assertStringContainsString("Job 'phpstan_src': key 'files' is CLI-only", $errors);
        $this->assertStringContainsString("Job 'phpstan_src': key 'files-from' is CLI-only", $errors);
    }

    /** @test */
    public function it_does_not_warn_for_valid_custom_job_keys()
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('audit', [
            'type' => 'custom',
            'script' => 'composer audit',
            'ignore-errors-on-exit' => true,
            'fail-fast' => false,
        ], $this->registry, $result);

        $this->assertEmpty($result->getWarnings());
        $this->assertFalse($result->hasErrors());
    }

    /** @test */
    public function it_warns_when_paths_is_string_outside_argument_map()
    {
        // phpmd has paths handled manually (not in ARGUMENT_MAP), so the common key validation must catch it
        $result = new ValidationResult();
        JobConfiguration::fromArray('phpmd_test', [
            'type' => 'phpmd',
            'paths' => 'src',
            'rules' => 'codesize',
        ], $this->registry, $result, new JobRegistry());

        $found = false;
        foreach ($result->getWarnings() as $w) {
            if (strpos($w, 'paths') !== false && strpos($w, 'array') !== false) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'Expected warning about paths not being an array');
    }

    /** @test */
    public function it_warns_when_rules_is_not_string()
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('phpmd_test', [
            'type' => 'phpmd',
            'paths' => ['src'],
            'rules' => 123,
        ], $this->registry, $result, new JobRegistry());

        $found = false;
        foreach ($result->getWarnings() as $w) {
            if (strpos($w, 'rules') !== false && strpos($w, 'string') !== false) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'Expected warning about rules not being a string');
    }

    // ========================================================================
    // Execution mode (TDD — will fail until implementation exists)
    // ========================================================================

    /** @test */
    public function it_parses_job_with_fast_execution()
    {
        $result = new ValidationResult();
        $job = JobConfiguration::fromArray('phpstan_src', [
            'type'      => 'phpstan',
            'paths'     => ['src'],
            'execution' => 'fast',
        ], $this->registry, $result);

        $this->assertFalse($result->hasErrors());
        $this->assertEquals('fast', $job->getExecution());
    }

    /** @test */
    public function it_parses_job_with_fast_branch_execution()
    {
        $result = new ValidationResult();
        $job = JobConfiguration::fromArray('phpstan_src', [
            'type'      => 'phpstan',
            'paths'     => ['src'],
            'execution' => 'fast-branch',
        ], $this->registry, $result);

        $this->assertFalse($result->hasErrors());
        $this->assertEquals('fast-branch', $job->getExecution());
    }

    /** @test */
    public function it_defaults_execution_to_null()
    {
        $result = new ValidationResult();
        $job = JobConfiguration::fromArray('phpstan_src', [
            'type'  => 'phpstan',
            'paths' => ['src'],
        ], $this->registry, $result);

        $this->assertNull($job->getExecution());
    }

    /**
     * @test
     * Kills L74 Concat/ConcatOperandRemoval and L75 ReturnRemoval:
     * invalid execution must produce the exact error string AND return null.
     */
    public function it_reports_error_and_returns_null_for_invalid_execution_mode()
    {
        $result = new ValidationResult();
        $job = JobConfiguration::fromArray('phpstan_src', [
            'type'      => 'phpstan',
            'execution' => 'invalid',
        ], $this->registry, $result);

        $this->assertNull($job);
        $this->assertErrorEquals(
            "Job 'phpstan_src': 'execution' must be one of: full, fast, fast-branch.",
            $result
        );
    }

    /** @test */
    public function execution_does_not_trigger_unknown_key_warning_in_job()
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('phpstan_src', [
            'type'      => 'phpstan',
            'paths'     => ['src'],
            'execution' => 'full',
        ], $this->registry, $result, new JobRegistry());

        $unknownWarnings = array_filter($result->getWarnings(), function (string $w) {
            return strpos($w, 'execution') !== false && strpos($w, 'nknown') !== false;
        });
        $this->assertEmpty($unknownWarnings);
    }

    /**
     * @test
     * @dataProvider validExecutionModeProvider
     */
    public function it_accepts_all_valid_execution_modes(string $mode)
    {
        $result = new ValidationResult();
        $job = JobConfiguration::fromArray('phpstan_src', [
            'type'      => 'phpstan',
            'paths'     => ['src'],
            'execution' => $mode,
        ], $this->registry, $result);

        $this->assertFalse($result->hasErrors());
        $this->assertEquals($mode, $job->getExecution());
    }

    public function validExecutionModeProvider(): array
    {
        return [
            'full'        => ['full'],
            'fast'        => ['fast'],
            'fast-branch' => ['fast-branch'],
        ];
    }

    // ========================================================================
    // executable-prefix as known key
    // ========================================================================

    /** @test */
    public function executable_prefix_is_a_known_key_for_tool_jobs()
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('phpstan_src', [
            'type' => 'phpstan',
            'paths' => ['src'],
            'executable-prefix' => 'docker exec -i app',
        ], $this->registry, $result, new JobRegistry());

        $unknownWarnings = array_filter($result->getWarnings(), function (string $w) {
            return strpos($w, 'executable-prefix') !== false && strpos($w, 'nknown') !== false;
        });
        $this->assertEmpty($unknownWarnings);
    }

    /** @test */
    public function executable_prefix_null_is_a_known_key_for_tool_jobs()
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('phpstan_src', [
            'type' => 'phpstan',
            'paths' => ['src'],
            'executable-prefix' => null,
        ], $this->registry, $result, new JobRegistry());

        $this->assertFalse($result->hasErrors());
        $unknownWarnings = array_filter($result->getWarnings(), function (string $w) {
            return strpos($w, 'executable-prefix') !== false;
        });
        $this->assertEmpty($unknownWarnings);
    }

    /** @test */
    public function executable_prefix_is_a_known_key_for_custom_jobs()
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('lint_js', [
            'type' => 'custom',
            'script' => 'npm run lint',
            'executable-prefix' => 'docker exec -i app',
        ], $this->registry, $result);

        $unknownWarnings = array_filter($result->getWarnings(), function (string $w) {
            return strpos($w, 'executable-prefix') !== false;
        });
        $this->assertEmpty($unknownWarnings);
    }

    // ========================================================================
    // v3-only type validation (types in JobRegistry but not ToolRegistry)
    // ========================================================================

    /** @test */
    public function it_validates_v3_only_type_when_job_registry_provided()
    {
        $result = new ValidationResult();
        $job = JobConfiguration::fromArray('fixer_src', [
            'type'   => 'php-cs-fixer',
            'paths'  => ['src'],
            'config' => '.php-cs-fixer.dist.php',
        ], $this->registry, $result, new JobRegistry());

        $this->assertFalse($result->hasErrors());
        $this->assertNotNull($job);
        $this->assertEquals('php-cs-fixer', $job->getType());
    }

    /** @test */
    public function it_validates_v3_only_type_rector_when_job_registry_provided()
    {
        $result = new ValidationResult();
        $job = JobConfiguration::fromArray('rector_src', [
            'type'   => 'rector',
            'paths'  => ['src'],
            'config' => 'rector.php',
        ], $this->registry, $result, new JobRegistry());

        $this->assertFalse($result->hasErrors());
        $this->assertNotNull($job);
        $this->assertEquals('rector', $job->getType());
    }

    /** @test */
    public function it_still_rejects_unknown_type_when_both_registries_provided()
    {
        $result = new ValidationResult();
        $job = JobConfiguration::fromArray('bad_job', [
            'type' => 'nonexistent',
        ], $this->registry, $result, new JobRegistry());

        $this->assertNull($job);
        $this->assertTrue($result->hasErrors());
        $this->assertStringContainsString('not a supported tool', $result->getErrors()[0]);
    }

    /** @test */
    public function it_rejects_v3_only_type_when_job_registry_is_null()
    {
        $result = new ValidationResult();
        $job = JobConfiguration::fromArray('fixer_src', [
            'type' => 'php-cs-fixer',
            'paths' => ['src'],
        ], $this->registry, $result);

        $this->assertNull($job);
        $this->assertTrue($result->hasErrors());
        $this->assertStringContainsString('not a supported tool', $result->getErrors()[0]);
    }

    // ========================================================================
    // cores: N keyword — validation and conflict warning
    // ========================================================================

    /** @test */
    public function it_accepts_cores_as_positive_integer()
    {
        $result = new ValidationResult();
        $job = JobConfiguration::fromArray('phpcs_src', [
            'type'  => 'phpcs',
            'paths' => ['src'],
            'cores' => 4,
        ], $this->registry, $result, new JobRegistry());

        $this->assertFalse($result->hasErrors());
        $this->assertEmpty($result->getWarnings(), 'cores as valid int should not emit warnings');
        $this->assertNotNull($job);
        $this->assertSame(4, $job->getConfig()['cores']);
    }

    /** @test */
    public function it_warns_when_cores_is_not_a_positive_integer()
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('phpcs_src', [
            'type'  => 'phpcs',
            'paths' => ['src'],
            'cores' => 0,
        ], $this->registry, $result, new JobRegistry());

        $warningText = implode(' ', $result->getWarnings());
        $this->assertStringContainsString("Job 'phpcs_src'", $warningText);
        $this->assertStringContainsString("'cores'", $warningText);
        $this->assertStringContainsString('must be a positive integer', $warningText);
    }

    /** @test */
    public function it_warns_when_cores_is_a_string()
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('phpcs_src', [
            'type'  => 'phpcs',
            'paths' => ['src'],
            'cores' => '4',
        ], $this->registry, $result, new JobRegistry());

        $warnings = $result->getWarnings();
        $found = false;
        foreach ($warnings as $w) {
            if (strpos($w, "'cores' must be a positive integer") !== false) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'string value should produce a positive-integer warning');
    }

    /** @test */
    public function it_warns_when_cores_coexists_with_phpcs_parallel()
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('phpcs_src', [
            'type'     => 'phpcs',
            'paths'    => ['src'],
            'parallel' => 8,
            'cores'    => 2,
        ], $this->registry, $result, new JobRegistry());

        $warningText = implode(' ', $result->getWarnings());
        $this->assertStringContainsString("Job 'phpcs_src'", $warningText);
        $this->assertStringContainsString("'cores' overrides 'parallel'", $warningText);
        $this->assertStringContainsString('cores=2', $warningText);
        $this->assertStringContainsString('parallel=8', $warningText);
    }

    /** @test */
    public function it_warns_when_cores_coexists_with_paratest_processes()
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('paratest_all', [
            'type'      => 'paratest',
            'processes' => 4,
            'cores'     => 2,
        ], $this->registry, $result, new JobRegistry());

        $warnings = $result->getWarnings();
        $found = false;
        foreach ($warnings as $w) {
            if (strpos($w, "'cores' overrides 'processes'") !== false) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found);
    }

    /** @test */
    public function it_does_not_warn_when_only_cores_or_only_native_thread_key_is_set()
    {
        $onlyCores = new ValidationResult();
        JobConfiguration::fromArray('phpcs_src', [
            'type'  => 'phpcs',
            'paths' => ['src'],
            'cores' => 4,
        ], $this->registry, $onlyCores, new JobRegistry());

        $onlyNative = new ValidationResult();
        JobConfiguration::fromArray('phpcs_src', [
            'type'     => 'phpcs',
            'paths'    => ['src'],
            'parallel' => 4,
        ], $this->registry, $onlyNative, new JobRegistry());

        $this->assertFalse($this->warningsContain($onlyCores->getWarnings(), 'overrides'));
        $this->assertFalse($this->warningsContain($onlyNative->getWarnings(), 'overrides'));
    }

    /** @test */
    public function cores_is_accepted_on_custom_jobs_without_unknown_key_warning()
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('paratest_wrapper', [
            'type'   => 'custom',
            'script' => 'vendor/bin/paratest --processes=4',
            'cores'  => 4,
        ], $this->registry, $result, new JobRegistry());

        $this->assertFalse($this->warningsContain($result->getWarnings(), "unknown key 'cores'"));
    }

    /** @test */
    public function phpstan_with_cores_is_budget_only_and_emits_no_conflict_warning()
    {
        // phpstan has no CLI flag for workers, so it is absent from THREAD_ARG_KEYS.
        // Declaring cores: 4 next to phpstan-native keys must NOT trigger a conflict warning.
        $result = new ValidationResult();
        JobConfiguration::fromArray('phpstan_src', [
            'type'   => 'phpstan',
            'paths'  => ['src'],
            'config' => 'qa/phpstan.neon',
            'cores'  => 4,
        ], $this->registry, $result, new JobRegistry());

        $this->assertFalse($this->warningsContain($result->getWarnings(), 'overrides'));
    }

    /** @test */
    public function it_warns_when_cores_coexists_with_phpcbf_parallel()
    {
        // phpcbf inherits PhpcsJob's controllable capability — declaring both
        // `cores` and `parallel` must emit the same conflict warning as phpcs.
        $result = new ValidationResult();
        JobConfiguration::fromArray('phpcbf_src', [
            'type'     => 'phpcbf',
            'paths'    => ['src'],
            'parallel' => 8,
            'cores'    => 2,
        ], $this->registry, $result, new JobRegistry());

        $warningText = implode(' ', $result->getWarnings());
        $this->assertStringContainsString("Job 'phpcbf_src'", $warningText);
        $this->assertStringContainsString("'cores' overrides 'parallel'", $warningText);
        $this->assertStringContainsString('cores=2', $warningText);
        $this->assertStringContainsString('parallel=8', $warningText);
    }

    /** @test */
    public function it_warns_when_cores_is_declared_on_a_single_threaded_tool_phpmd()
    {
        // phpmd has no internal threading; cores>1 reserves slots in the budget
        // without benefit and slows admission of other jobs.
        $result = new ValidationResult();
        JobConfiguration::fromArray('phpmd_src', [
            'type'  => 'phpmd',
            'paths' => ['src'],
            'rules' => 'cleancode',
            'cores' => 4,
        ], $this->registry, $result, new JobRegistry());

        $warningText = implode(' ', $result->getWarnings());
        $this->assertStringContainsString("Job 'phpmd_src'", $warningText);
        $this->assertStringContainsString('single-threaded', $warningText);
        $this->assertStringContainsString("'cores'", $warningText);
    }

    /** @test */
    public function it_warns_when_cores_is_declared_on_a_single_threaded_tool_phpunit()
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('phpunit_all', [
            'type'  => 'phpunit',
            'cores' => 4,
        ], $this->registry, $result, new JobRegistry());

        $this->assertTrue($this->warningsContain($result->getWarnings(), 'single-threaded'));
    }

    /** @test */
    public function it_warns_when_cores_is_declared_on_a_single_threaded_tool_phpcpd()
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('phpcpd_src', [
            'type'  => 'phpcpd',
            'paths' => ['src'],
            'cores' => 4,
        ], $this->registry, $result, new JobRegistry());

        $this->assertTrue($this->warningsContain($result->getWarnings(), 'single-threaded'));
    }

    /** @test */
    public function it_does_not_warn_when_cores_is_one_on_single_threaded_tool()
    {
        // cores: 1 is the effective default for non-threaded jobs; declaring
        // it explicitly is harmless documentation and must not warn.
        $result = new ValidationResult();
        JobConfiguration::fromArray('phpmd_src', [
            'type'  => 'phpmd',
            'paths' => ['src'],
            'rules' => 'cleancode',
            'cores' => 1,
        ], $this->registry, $result, new JobRegistry());

        $this->assertFalse($this->warningsContain($result->getWarnings(), 'single-threaded'));
    }

    /** @test */
    public function it_does_not_warn_about_single_threaded_when_cores_absent()
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('phpmd_src', [
            'type'  => 'phpmd',
            'paths' => ['src'],
            'rules' => 'cleancode',
        ], $this->registry, $result, new JobRegistry());

        $this->assertFalse($this->warningsContain($result->getWarnings(), 'single-threaded'));
    }

    /** @test */
    public function it_does_not_warn_about_single_threaded_when_type_is_custom()
    {
        // custom jobs may run scripts with their own internal parallelism
        // (npx eslint --concurrency=N, etc.) that the system cannot inspect.
        // The user knows what they execute; do not emit the single-threaded
        // warning even with cores>1.
        $result = new ValidationResult();
        JobConfiguration::fromArray('eslint_src', [
            'type'   => 'custom',
            'script' => 'npx eslint src/',
            'cores'  => 4,
        ], $this->registry, $result, new JobRegistry());

        $this->assertFalse($this->warningsContain($result->getWarnings(), 'single-threaded'));
    }

    /** @param string[] $warnings */
    private function warningsContain(array $warnings, string $needle): bool
    {
        foreach ($warnings as $w) {
            if (strpos($w, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    // ========================================================================
    // warn-after / fail-after thresholds (v3.3 item 4)
    // ========================================================================

    /** @test */
    public function it_parses_warn_after_and_fail_after()
    {
        $result = new ValidationResult();
        $job = JobConfiguration::fromArray('phpunit_job', [
            'type'       => 'phpunit',
            'warn-after' => 60,
            'fail-after' => 180,
        ], $this->registry, $result, new JobRegistry());

        $this->assertFalse($result->hasErrors());
        $this->assertNotNull($job);
        $this->assertSame(60, $job->getWarnAfter());
        $this->assertSame(180, $job->getFailAfter());
        $this->assertTrue($job->hasThreshold());
    }

    /** @test */
    public function it_parses_warn_after_alone()
    {
        $result = new ValidationResult();
        $job = JobConfiguration::fromArray('phpcs_job', [
            'type'       => 'phpcs',
            'warn-after' => 5,
        ], $this->registry, $result, new JobRegistry());

        $this->assertFalse($result->hasErrors());
        $this->assertSame(5, $job->getWarnAfter());
        $this->assertNull($job->getFailAfter());
    }

    /** @test */
    public function it_returns_null_threshold_when_not_declared()
    {
        $result = new ValidationResult();
        $job = JobConfiguration::fromArray('phpstan_job', [
            'type' => 'phpstan',
        ], $this->registry, $result, new JobRegistry());

        $this->assertNull($job->getWarnAfter());
        $this->assertNull($job->getFailAfter());
        $this->assertFalse($job->hasThreshold());
    }

    /** @test */
    public function it_rejects_warn_after_greater_or_equal_to_fail_after_in_job()
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('phpunit_job', [
            'type'       => 'phpunit',
            'warn-after' => 60,
            'fail-after' => 45,
        ], $this->registry, $result, new JobRegistry());

        $this->assertTrue($result->hasErrors());
        $errorText = implode(' ', $result->getErrors());
        $this->assertStringContainsString("Job 'phpunit_job'", $errorText);
        $this->assertStringContainsString("'warn-after'", $errorText);
        $this->assertStringContainsString("'fail-after'", $errorText);
        $this->assertStringContainsString('must be less than', $errorText);
        $this->assertStringContainsString('(60)', $errorText);
        $this->assertStringContainsString('(45)', $errorText);
    }

    /** @test */
    public function it_rejects_zero_warn_after_in_job()
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('phpcs_job', [
            'type'       => 'phpcs',
            'warn-after' => 0,
        ], $this->registry, $result, new JobRegistry());

        $this->assertTrue($result->hasErrors());
        $errorText = $result->getErrors()[0];
        $this->assertStringContainsString("Job 'phpcs_job'", $errorText);
        $this->assertStringContainsString("'warn-after'", $errorText);
        $this->assertStringContainsString('positive integer', $errorText);
        $this->assertStringContainsString('seconds', $errorText);
    }

    /** @test */
    public function it_rejects_non_integer_threshold_in_job()
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('phpcs_job', [
            'type'       => 'phpcs',
            'warn-after' => 'fast',
        ], $this->registry, $result, new JobRegistry());

        $this->assertTrue($result->hasErrors());
        $errorText = $result->getErrors()[0];
        $this->assertStringContainsString("'warn-after'", $errorText);
        $this->assertStringContainsString('positive integer', $errorText);
    }

    /**
     * @test
     * BUG-10: time-budget inside a job is now a hard error (was a warning).
     * conf:check exits 1 and the suggestion to use warn-after/fail-after stays
     * in the message.
     */
    public function it_errors_when_time_budget_is_declared_in_a_job()
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('phpunit_job', [
            'type'        => 'phpunit',
            'time-budget' => ['warn-after' => 60],
        ], $this->registry, $result, new JobRegistry());

        $this->assertTrue($result->hasErrors());
        $errorText = implode(' ', $result->getErrors());
        $this->assertStringContainsString("Job 'phpunit_job'", $errorText);
        $this->assertStringContainsString("'time-budget'", $errorText);
        $this->assertStringContainsString('not valid in jobs', $errorText);
        $this->assertStringContainsString("'warn-after'/'fail-after'", $errorText);
    }

    /** @test */
    public function threshold_keys_are_known_for_typed_jobs()
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('phpunit_job', [
            'type'       => 'phpunit',
            'warn-after' => 60,
            'fail-after' => 180,
        ], $this->registry, $result, new JobRegistry());

        $this->assertFalse($this->warningsContain($result->getWarnings(), 'unknown key'));
    }

    /** @test */
    public function threshold_keys_are_known_for_custom_jobs()
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('lint_js', [
            'type'       => 'custom',
            'script'     => 'npm run lint',
            'warn-after' => 30,
            'fail-after' => 90,
        ], $this->registry, $result);

        $this->assertFalse($result->hasErrors());
        $this->assertFalse($this->warningsContain($result->getWarnings(), 'unknown key'));
    }

    // ========================================================================
    // memory threshold per-job (v3.3 — gh-48)
    // ========================================================================

    /** @test */
    public function it_accepts_short_form_memory_as_positive_integer(): void
    {
        $result = new ValidationResult();
        $job = JobConfiguration::fromArray('phpstan_job', [
            'type'   => 'phpstan',
            'memory' => 2000,
        ], $this->registry, $result, new JobRegistry());

        $this->assertFalse($result->hasErrors());
        $this->assertNotNull($job);
        $threshold = $job->getMemoryThreshold();
        $this->assertNotNull($threshold);
        $this->assertSame(2000, $threshold->getWarnAbove());
        $this->assertTrue($threshold->isShortForm());
        $this->assertSame(2000, $job->getMemoryReserve());
        $this->assertTrue($job->hasMemoryThreshold());
    }

    /** @test */
    public function it_accepts_extended_form_memory_with_warn_and_fail_above(): void
    {
        $result = new ValidationResult();
        $job = JobConfiguration::fromArray('phpunit_job', [
            'type'   => 'phpunit',
            'memory' => ['warn-above' => 1500, 'fail-above' => 2000],
        ], $this->registry, $result, new JobRegistry());

        $this->assertFalse($result->hasErrors());
        $threshold = $job->getMemoryThreshold();
        $this->assertNotNull($threshold);
        $this->assertSame(1500, $threshold->getWarnAbove());
        $this->assertSame(2000, $threshold->getFailAbove());
        $this->assertFalse($threshold->isShortForm());
        $this->assertNull($job->getMemoryReserve());
    }

    /** @test */
    public function it_returns_null_memory_threshold_when_not_declared(): void
    {
        $result = new ValidationResult();
        $job = JobConfiguration::fromArray('phpstan_job', [
            'type' => 'phpstan',
        ], $this->registry, $result, new JobRegistry());

        $this->assertNull($job->getMemoryThreshold());
        $this->assertNull($job->getMemoryReserve());
        $this->assertFalse($job->hasMemoryThreshold());
    }

    /** @test */
    public function it_rejects_zero_memory_short_form(): void
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('phpstan_job', [
            'type'   => 'phpstan',
            'memory' => 0,
        ], $this->registry, $result, new JobRegistry());

        $this->assertTrue($result->hasErrors());
        $errorText = $result->getErrors()[0];
        $this->assertStringContainsString("Job 'phpstan_job'", $errorText);
        $this->assertStringContainsString("'memory'", $errorText);
        $this->assertStringContainsString('positive integer', $errorText);
        $this->assertStringContainsString('MB', $errorText);
    }

    /** @test */
    public function it_rejects_negative_memory_short_form(): void
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('phpstan_job', [
            'type'   => 'phpstan',
            'memory' => -100,
        ], $this->registry, $result, new JobRegistry());

        $this->assertTrue($result->hasErrors());
        $errorText = $result->getErrors()[0];
        $this->assertStringContainsString("'memory'", $errorText);
        $this->assertStringContainsString('positive integer', $errorText);
    }

    /** @test */
    public function it_rejects_warn_above_greater_or_equal_to_fail_above_in_memory(): void
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('phpunit_job', [
            'type'   => 'phpunit',
            'memory' => ['warn-above' => 2000, 'fail-above' => 1500],
        ], $this->registry, $result, new JobRegistry());

        $this->assertTrue($result->hasErrors());
        $errorText = implode(' ', $result->getErrors());
        $this->assertStringContainsString("Job 'phpunit_job'", $errorText);
        $this->assertStringContainsString("'warn-above'", $errorText);
        $this->assertStringContainsString("'fail-above'", $errorText);
        $this->assertStringContainsString('must be less than', $errorText);
        $this->assertStringContainsString('(2000)', $errorText);
        $this->assertStringContainsString('(1500)', $errorText);
        $this->assertStringContainsString("in 'memory'", $errorText);
    }

    /** @test */
    public function it_rejects_string_memory_value(): void
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('phpstan_job', [
            'type'   => 'phpstan',
            'memory' => '2000',
        ], $this->registry, $result, new JobRegistry());

        $this->assertTrue($result->hasErrors());
        $errorText = $result->getErrors()[0];
        $this->assertStringContainsString("Job 'phpstan_job'", $errorText);
        $this->assertStringContainsString("'memory'", $errorText);
        $this->assertStringContainsString('must be either a positive integer (MB) or an object', $errorText);
        $this->assertStringContainsString("'warn-above'/'fail-above'", $errorText);
    }

    /** @test */
    public function memory_key_is_known_for_typed_jobs(): void
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('phpstan_job', [
            'type'   => 'phpstan',
            'memory' => 2000,
        ], $this->registry, $result, new JobRegistry());

        $this->assertFalse($this->warningsContain($result->getWarnings(), 'unknown key'));
    }

    /** @test */
    public function memory_key_is_known_for_custom_jobs(): void
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('lint_js', [
            'type'   => 'custom',
            'script' => 'npm run lint',
            'memory' => 512,
        ], $this->registry, $result);

        $this->assertFalse($result->hasErrors());
        $this->assertFalse($this->warningsContain($result->getWarnings(), 'unknown key'));
    }

    // ========================================================================
    // Mutation testing reinforcements: boundaries, getters, conflict logic
    // ========================================================================

    /** @test */
    public function it_records_conflict_error_with_full_message_for_deprecated_and_kebab_keys(): void
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('phpstan_job', [
            'type'           => 'phpstan',
            'executablePath' => 'vendor/bin/phpstan',
            'executable-path' => 'vendor/bin/phpstan',
        ], $this->registry, $result, new JobRegistry());

        $this->assertTrue($result->hasErrors());
        $errorText = implode(' ', $result->getErrors());
        $this->assertStringContainsString("Job 'phpstan_job'", $errorText);
        $this->assertStringContainsString('conflicting keys', $errorText);
        $this->assertStringContainsString("'executablePath'", $errorText);
        $this->assertStringContainsString("'executable-path'", $errorText);
        $this->assertStringContainsString('Use only one', $errorText);
        $this->assertStringContainsString('kebab-case', $errorText);
    }

    /** @test */
    public function it_does_not_record_deprecation_when_conflict_detected(): void
    {
        // Kills the Continue_ mutant in normalizeDeprecatedKeys: without
        // continue, the function would fall through to the deprecation
        // recording branch even though it already raised a conflict error.
        $result = new ValidationResult();
        JobConfiguration::fromArray('phpstan_job', [
            'type'           => 'phpstan',
            'executablePath' => 'vendor/bin/phpstan',
            'executable-path' => 'vendor/bin/phpstan',
        ], $this->registry, $result, new JobRegistry());

        $this->assertEmpty(
            $result->getDeprecations(),
            'no deprecation should be recorded when the conflict is reported'
        );
    }

    /**
     * @test
     * Mata el mutante Continue_ → Break_ en línea 150 (`normalizeDeprecatedKeys`):
     * con un conflict en el primer par y una deprecación legítima en otro
     * par posterior, real reporta ambos (error + deprecation); mutado
     * abortaría el foreach y no añadiría la deprecación del segundo par.
     */
    public function conflict_in_first_deprecated_pair_does_not_abort_processing_of_remaining_pairs(): void
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('phpstan_job', [
            'type'            => 'phpstan',
            // Conflicto: par 1 ('executablePath' vs 'executable-path').
            'executablePath'  => 'vendor/bin/phpstan',
            'executable-path' => 'vendor/bin/phpstan',
            // Deprecación legítima: par 4 ('failFast' sin 'fail-fast').
            'failFast'        => true,
        ], $this->registry, $result, new JobRegistry());

        $this->assertTrue($result->hasErrors());
        $deprecations = $result->getDeprecations();
        $this->assertCount(1, $deprecations);
        $this->assertSame('failFast', $deprecations[0]->getOldKey());
        $this->assertSame('fail-fast', $deprecations[0]->getNewKey());
    }

    /** @test */
    public function it_accepts_short_form_memory_value_of_exactly_one(): void
    {
        // Kills LessThan boundary on `$value < 1` short-form memory validator.
        $result = new ValidationResult();
        $job = JobConfiguration::fromArray('phpstan_job', [
            'type'   => 'phpstan',
            'memory' => 1,
        ], $this->registry, $result, new JobRegistry());

        $this->assertFalse($result->hasErrors());
        $this->assertNotNull($job);
        $threshold = $job->getMemoryThreshold();
        $this->assertNotNull($threshold);
        $this->assertSame(1, $threshold->getWarnAbove());
    }

    /** @test */
    public function it_accepts_warn_after_value_of_exactly_one(): void
    {
        // Kills LessThan boundary on `$value < 1` in extractPositiveInt for
        // warn-after / fail-after.
        $result = new ValidationResult();
        $job = JobConfiguration::fromArray('phpcs_job', [
            'type'       => 'phpcs',
            'warn-after' => 1,
            'fail-after' => 2,
        ], $this->registry, $result, new JobRegistry());

        $this->assertFalse($result->hasErrors());
        $this->assertNotNull($job);
        $this->assertSame(1, $job->getWarnAfter());
        $this->assertSame(2, $job->getFailAfter());
    }

    /** @test */
    public function it_accepts_cores_value_of_exactly_one(): void
    {
        // Kills LessThan boundary on `$cores < 1` in validateCoresKey.
        $result = new ValidationResult();
        $job = JobConfiguration::fromArray('phpcs_job', [
            'type'  => 'phpcs',
            'paths' => ['src'],
            'cores' => 1,
        ], $this->registry, $result, new JobRegistry());

        $this->assertFalse($result->hasErrors());
        $this->assertEmpty($result->getWarnings(), 'cores=1 must not warn');
        $this->assertNotNull($job);
        $this->assertSame(1, $job->getConfig()['cores']);
    }

    /** @test */
    public function it_does_not_emit_cores_override_warning_when_cores_invalid(): void
    {
        // Kills the ReturnRemoval mutant in validateCoresKey: without the
        // early return after the "must be a positive integer" warning, the
        // function would fall through to the THREAD_ARG_KEYS check and
        // emit a SECOND, spurious "cores overrides parallel" warning even
        // though the cores value is invalid.
        $result = new ValidationResult();
        JobConfiguration::fromArray('phpcs_job', [
            'type'     => 'phpcs',
            'paths'    => ['src'],
            'parallel' => 8,
            'cores'    => 0,
        ], $this->registry, $result, new JobRegistry());

        $warningText = implode(' ', $result->getWarnings());
        $this->assertStringContainsString('positive integer', $warningText);
        $this->assertStringNotContainsString('overrides', $warningText);
    }

    /** @test */
    public function get_warn_after_returns_null_when_stored_value_is_zero(): void
    {
        // Kills `$value > 0` GreaterThan + LogicalAnd mutants in getWarnAfter().
        // Bypasses fromArray so the invalid value lands in the config.
        $job = new JobConfiguration('phpcs_job', 'phpcs', ['warn-after' => 0]);

        $this->assertNull($job->getWarnAfter());
    }

    /** @test */
    public function get_warn_after_returns_value_one_when_stored_at_boundary(): void
    {
        $job = new JobConfiguration('phpcs_job', 'phpcs', ['warn-after' => 1]);

        $this->assertSame(1, $job->getWarnAfter());
    }

    /** @test */
    public function get_warn_after_returns_null_for_negative_stored_value(): void
    {
        $job = new JobConfiguration('phpcs_job', 'phpcs', ['warn-after' => -5]);

        $this->assertNull($job->getWarnAfter());
    }

    /** @test */
    public function get_fail_after_returns_null_when_stored_value_is_zero(): void
    {
        // Kills `$value > 0` GreaterThan + LogicalAnd mutants in getFailAfter().
        $job = new JobConfiguration('phpcs_job', 'phpcs', ['fail-after' => 0]);

        $this->assertNull($job->getFailAfter());
    }

    /** @test */
    public function get_fail_after_returns_value_one_when_stored_at_boundary(): void
    {
        $job = new JobConfiguration('phpcs_job', 'phpcs', ['fail-after' => 1]);

        $this->assertSame(1, $job->getFailAfter());
    }

    /** @test */
    public function has_threshold_returns_true_when_only_warn_after_is_set(): void
    {
        // Kills LogicalOr `||` -> `&&` mutant in hasThreshold(): with `&&`
        // a single-threshold config would return false.
        $job = new JobConfiguration('phpcs_job', 'phpcs', ['warn-after' => 60]);

        $this->assertTrue($job->hasThreshold());
    }

    /** @test */
    public function has_threshold_returns_true_when_only_fail_after_is_set(): void
    {
        $job = new JobConfiguration('phpcs_job', 'phpcs', ['fail-after' => 180]);

        $this->assertTrue($job->hasThreshold());
    }

    /** @test */
    public function has_threshold_returns_false_when_neither_is_set(): void
    {
        $job = new JobConfiguration('phpcs_job', 'phpcs', []);

        $this->assertFalse($job->hasThreshold());
    }

    /** @test */
    public function get_memory_threshold_returns_null_for_zero_short_form_in_config(): void
    {
        // Kills `$value > 0` GreaterThan in getMemoryThreshold() short-form
        // resolution. Bypasses fromArray so the invalid value lands raw.
        $job = new JobConfiguration('phpstan_job', 'phpstan', ['memory' => 0]);

        $this->assertNull($job->getMemoryThreshold());
    }

    /** @test */
    public function get_memory_threshold_returns_short_form_for_value_one_in_config(): void
    {
        $job = new JobConfiguration('phpstan_job', 'phpstan', ['memory' => 1]);

        $threshold = $job->getMemoryThreshold();
        $this->assertNotNull($threshold);
        $this->assertSame(1, $threshold->getWarnAbove());
        $this->assertTrue($threshold->isShortForm());
    }
}
