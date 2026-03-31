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
}
