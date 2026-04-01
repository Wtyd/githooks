<?php

declare(strict_types=1);

namespace Tests\Unit\Configuration;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Configuration\ValidationResult;
use Wtyd\GitHooks\Jobs\JobRegistry;
use Wtyd\GitHooks\Registry\ToolRegistry;

class JobConfigurationTest extends TestCase
{
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
        $this->assertStringContainsString("'script' key", $result->getErrors()[0]);
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

    /** @test */
    public function it_warns_when_csv_arg_is_not_array_or_string()
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('test', [
            'type' => 'phpcs',
            'ignore' => 123,
        ], $this->registry, $result, new JobRegistry());

        $warnings = $result->getWarnings();
        $found = false;
        foreach ($warnings as $w) {
            if (strpos($w, 'ignore') !== false) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'Expected warning about ignore type');
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
}
