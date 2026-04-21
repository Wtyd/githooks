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
        $this->assertStringContainsString("'script' or 'executablePath' key", $result->getErrors()[0]);
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

    /** @test */
    public function it_does_not_warn_for_valid_custom_job_keys()
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('audit', [
            'type' => 'custom',
            'script' => 'composer audit',
            'ignoreErrorsOnExit' => true,
            'failFast' => false,
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
}
