<?php

declare(strict_types=1);

namespace Tests\Unit\Configuration;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Configuration\ValidationResult;

class OptionsConfigurationTest extends TestCase
{
    /** @test */
    public function it_uses_defaults_when_empty()
    {
        $options = new OptionsConfiguration();

        $this->assertFalse($options->isFailFast());
        $this->assertEquals(1, $options->getProcesses());
    }

    /** @test */
    public function it_parses_valid_options()
    {
        $result = new ValidationResult();
        $options = OptionsConfiguration::fromArray(['fail-fast' => true, 'processes' => 4], $result);

        $this->assertFalse($result->hasErrors());
        $this->assertTrue($options->isFailFast());
        $this->assertEquals(4, $options->getProcesses());
    }

    /** @test */
    public function it_reports_error_for_non_boolean_fail_fast()
    {
        $result = new ValidationResult();
        OptionsConfiguration::fromArray(['fail-fast' => 'yes'], $result);

        $this->assertTrue($result->hasErrors());
        $this->assertStringContainsString('fail-fast', $result->getErrors()[0]);
    }

    /** @test */
    public function it_reports_error_for_non_integer_processes()
    {
        $result = new ValidationResult();
        OptionsConfiguration::fromArray(['processes' => 'many'], $result);

        $this->assertTrue($result->hasErrors());
        $this->assertStringContainsString('processes', $result->getErrors()[0]);
    }

    /** @test */
    public function it_reports_error_for_negative_processes()
    {
        $result = new ValidationResult();
        OptionsConfiguration::fromArray(['processes' => -1], $result);

        $this->assertTrue($result->hasErrors());
    }

    /** @test */
    public function it_warns_about_unknown_keys()
    {
        $result = new ValidationResult();
        OptionsConfiguration::fromArray(['fail-fast' => false, 'unknown' => 'value'], $result);

        $this->assertFalse($result->hasErrors());
        $this->assertCount(1, $result->getWarnings());
        $this->assertStringContainsString('unknown', $result->getWarnings()[0]);
    }

    /** @test */
    public function defaults_returns_default_values()
    {
        $options = OptionsConfiguration::defaults();

        $this->assertFalse($options->isFailFast());
        $this->assertEquals(1, $options->getProcesses());
    }

    /** @test */
    public function with_overrides_applies_fail_fast()
    {
        $options = new OptionsConfiguration(false, 2);
        $overridden = $options->withOverrides(true, null);

        $this->assertTrue($overridden->isFailFast());
        $this->assertEquals(2, $overridden->getProcesses());
    }

    /** @test */
    public function with_overrides_applies_processes()
    {
        $options = new OptionsConfiguration(true, 1);
        $overridden = $options->withOverrides(null, 8);

        $this->assertTrue($overridden->isFailFast());
        $this->assertEquals(8, $overridden->getProcesses());
    }

    /** @test */
    public function with_overrides_applies_both()
    {
        $options = new OptionsConfiguration(false, 1);
        $overridden = $options->withOverrides(true, 4);

        $this->assertTrue($overridden->isFailFast());
        $this->assertEquals(4, $overridden->getProcesses());
    }

    /** @test */
    public function with_overrides_keeps_original_when_nulls()
    {
        $options = new OptionsConfiguration(true, 8);
        $overridden = $options->withOverrides(null, null);

        $this->assertTrue($overridden->isFailFast());
        $this->assertEquals(8, $overridden->getProcesses());
    }

    /** @test */
    public function with_overrides_does_not_mutate_original()
    {
        $original = new OptionsConfiguration(false, 1);
        $original->withOverrides(true, 4);

        $this->assertFalse($original->isFailFast());
        $this->assertEquals(1, $original->getProcesses());
    }

    // ========================================================================
    // Execution mode options (TDD — will fail until implementation exists)
    // ========================================================================

    /** @test */
    public function it_parses_main_branch_option()
    {
        $result = new ValidationResult();
        $options = OptionsConfiguration::fromArray([
            'main-branch' => 'develop',
        ], $result);

        $this->assertFalse($result->hasErrors());
        $this->assertEquals('develop', $options->getMainBranch());
    }

    /** @test */
    public function it_defaults_main_branch_to_null()
    {
        $options = new OptionsConfiguration();

        $this->assertNull($options->getMainBranch());
    }

    /** @test */
    public function it_reports_error_for_non_string_main_branch()
    {
        $result = new ValidationResult();
        OptionsConfiguration::fromArray(['main-branch' => 123], $result);

        $this->assertTrue($result->hasErrors());
        $this->assertStringContainsString('main-branch', $result->getErrors()[0]);
    }

    /** @test */
    public function it_parses_fast_branch_fallback_as_full()
    {
        $result = new ValidationResult();
        $options = OptionsConfiguration::fromArray([
            'fast-branch-fallback' => 'full',
        ], $result);

        $this->assertFalse($result->hasErrors());
        $this->assertEquals('full', $options->getFastBranchFallback());
    }

    /** @test */
    public function it_parses_fast_branch_fallback_as_fast()
    {
        $result = new ValidationResult();
        $options = OptionsConfiguration::fromArray([
            'fast-branch-fallback' => 'fast',
        ], $result);

        $this->assertFalse($result->hasErrors());
        $this->assertEquals('fast', $options->getFastBranchFallback());
    }

    /** @test */
    public function it_defaults_fast_branch_fallback_to_full()
    {
        $options = new OptionsConfiguration();

        $this->assertEquals('full', $options->getFastBranchFallback());
    }

    /** @test */
    public function it_reports_error_for_invalid_fast_branch_fallback()
    {
        $result = new ValidationResult();
        OptionsConfiguration::fromArray(['fast-branch-fallback' => 'fast-branch'], $result);

        $this->assertTrue($result->hasErrors());
        $this->assertStringContainsString('fast-branch-fallback', $result->getErrors()[0]);
    }

    /** @test */
    public function main_branch_and_fast_branch_fallback_are_not_unknown_keys()
    {
        $result = new ValidationResult();
        OptionsConfiguration::fromArray([
            'main-branch' => 'master',
            'fast-branch-fallback' => 'full',
        ], $result);

        $this->assertFalse($result->hasErrors());
        $this->assertEmpty($result->getWarnings());
    }
}
