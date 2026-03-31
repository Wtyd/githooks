<?php

declare(strict_types=1);

namespace Tests\Unit\Configuration;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Configuration\ValidationResult;
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
}
