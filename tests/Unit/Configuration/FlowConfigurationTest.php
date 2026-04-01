<?php

declare(strict_types=1);

namespace Tests\Unit\Configuration;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\FlowConfiguration;
use Wtyd\GitHooks\Configuration\ValidationResult;

class FlowConfigurationTest extends TestCase
{
    /** @test */
    public function it_parses_a_valid_flow()
    {
        $result = new ValidationResult();
        $flow = FlowConfiguration::fromArray('lint', [
            'jobs' => ['phpcs_src', 'phpmd_src'],
        ], ['phpcs_src', 'phpmd_src'], $result);

        $this->assertFalse($result->hasErrors());
        $this->assertNotNull($flow);
        $this->assertEquals('lint', $flow->getName());
        $this->assertEquals(['phpcs_src', 'phpmd_src'], $flow->getJobs());
        $this->assertNull($flow->getOptions());
    }

    /** @test */
    public function it_parses_flow_with_options()
    {
        $result = new ValidationResult();
        $flow = FlowConfiguration::fromArray('lint', [
            'options' => ['fail-fast' => true],
            'jobs'    => ['phpcs_src'],
        ], ['phpcs_src'], $result);

        $this->assertFalse($result->hasErrors());
        $this->assertNotNull($flow->getOptions());
        $this->assertTrue($flow->getOptions()->isFailFast());
    }

    /** @test */
    public function it_rejects_flow_named_as_git_hook()
    {
        $result = new ValidationResult();
        $flow = FlowConfiguration::fromArray('pre-commit', [
            'jobs' => ['phpcs_src'],
        ], ['phpcs_src'], $result);

        $this->assertNull($flow);
        $this->assertTrue($result->hasErrors());
        $this->assertStringContainsString('git hook event name', $result->getErrors()[0]);
    }

    /** @test */
    public function it_reports_error_when_jobs_is_empty()
    {
        $result = new ValidationResult();
        $flow = FlowConfiguration::fromArray('lint', ['jobs' => []], ['phpcs_src'], $result);

        $this->assertNull($flow);
        $this->assertTrue($result->hasErrors());
    }

    /** @test */
    public function it_reports_error_when_jobs_is_missing()
    {
        $result = new ValidationResult();
        $flow = FlowConfiguration::fromArray('lint', ['options' => []], ['phpcs_src'], $result);

        $this->assertNull($flow);
        $this->assertTrue($result->hasErrors());
    }

    /** @test */
    public function it_reports_warning_for_undefined_job_reference()
    {
        $result = new ValidationResult();
        FlowConfiguration::fromArray('lint', [
            'jobs' => ['phpcs_src', 'nonexistent'],
        ], ['phpcs_src'], $result);

        $this->assertFalse($result->hasErrors());
        $this->assertNotEmpty($result->getWarnings());
        $this->assertStringContainsString('nonexistent', $result->getWarnings()[0]);
    }
}
