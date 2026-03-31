<?php

declare(strict_types=1);

namespace Tests\Unit\Configuration;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\HookConfiguration;
use Wtyd\GitHooks\Configuration\ValidationResult;

class HookConfigurationTest extends TestCase
{
    /** @test */
    public function it_parses_valid_hooks()
    {
        $result = new ValidationResult();
        $hooks = HookConfiguration::fromArray(
            ['pre-commit' => ['lint', 'test'], 'pre-push' => ['phpstan_src']],
            ['lint', 'test'],
            ['phpstan_src'],
            $result
        );

        $this->assertFalse($result->hasErrors());
        $this->assertEquals(['lint', 'test'], $hooks->resolve('pre-commit'));
        $this->assertEquals(['phpstan_src'], $hooks->resolve('pre-push'));
        $this->assertEmpty($hooks->resolve('post-commit'));
        $this->assertCount(2, $hooks->getEvents());
    }

    /** @test */
    public function it_reports_error_for_invalid_event_name()
    {
        $result = new ValidationResult();
        HookConfiguration::fromArray(
            ['not-a-hook' => ['lint']],
            ['lint'],
            [],
            $result
        );

        $this->assertTrue($result->hasErrors());
        $this->assertStringContainsString('not a valid git hook', $result->getErrors()[0]);
    }

    /** @test */
    public function it_reports_error_for_undefined_reference()
    {
        $result = new ValidationResult();
        HookConfiguration::fromArray(
            ['pre-commit' => ['nonexistent_flow']],
            [],
            [],
            $result
        );

        $this->assertTrue($result->hasErrors());
        $this->assertStringContainsString('nonexistent_flow', $result->getErrors()[0]);
    }

    /** @test */
    public function it_reports_error_for_empty_reference_array()
    {
        $result = new ValidationResult();
        HookConfiguration::fromArray(
            ['pre-commit' => []],
            ['lint'],
            [],
            $result
        );

        $this->assertTrue($result->hasErrors());
        $this->assertStringContainsString('non-empty array', $result->getErrors()[0]);
    }

    /** @test */
    public function it_accepts_job_references_in_hooks()
    {
        $result = new ValidationResult();
        $hooks = HookConfiguration::fromArray(
            ['pre-commit' => ['phpstan_src']],
            [],
            ['phpstan_src'],
            $result
        );

        $this->assertFalse($result->hasErrors());
        $this->assertEquals(['phpstan_src'], $hooks->resolve('pre-commit'));
    }
}
