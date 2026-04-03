<?php

declare(strict_types=1);

namespace Tests\Unit\Configuration;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\HookConfiguration;
use Wtyd\GitHooks\Configuration\HookRef;
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
        $this->assertTargets(['lint', 'test'], $hooks->resolve('pre-commit'));
        $this->assertTargets(['phpstan_src'], $hooks->resolve('pre-push'));
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
        $this->assertTargets(['phpstan_src'], $hooks->resolve('pre-commit'));
    }

    /** @test */
    public function it_parses_extended_format_with_conditions()
    {
        $result = new ValidationResult();
        $hooks = HookConfiguration::fromArray(
            [
                'pre-push' => [
                    ['flow' => 'fullAnalysis', 'only-on' => ['main', 'develop']],
                    ['job' => 'audit', 'only-files' => ['*.php']],
                ],
            ],
            ['fullAnalysis'],
            ['audit'],
            $result
        );

        $this->assertFalse($result->hasErrors());
        $refs = $hooks->resolve('pre-push');
        $this->assertCount(2, $refs);
        $this->assertEquals('fullAnalysis', $refs[0]->getTarget());
        $this->assertEquals(['main', 'develop'], $refs[0]->getOnlyOnBranches());
        $this->assertEquals('audit', $refs[1]->getTarget());
        $this->assertEquals(['*.php'], $refs[1]->getOnlyFiles());
    }

    /** @test */
    public function it_parses_exclude_files_condition()
    {
        $result = new ValidationResult();
        $hooks = HookConfiguration::fromArray(
            [
                'pre-commit' => [
                    ['job' => 'phpcs', 'only-files' => ['src/*'], 'exclude-files' => ['src/Process/*']],
                ],
            ],
            [],
            ['phpcs'],
            $result
        );

        $this->assertFalse($result->hasErrors());
        $refs = $hooks->resolve('pre-commit');
        $this->assertCount(1, $refs);
        $this->assertEquals(['src/*'], $refs[0]->getOnlyFiles());
        $this->assertEquals(['src/Process/*'], $refs[0]->getExcludeFiles());
        $this->assertTrue($refs[0]->hasConditions());
    }

    /** @test */
    public function it_parses_exclude_files_without_only_files()
    {
        $result = new ValidationResult();
        $hooks = HookConfiguration::fromArray(
            [
                'pre-commit' => [
                    ['flow' => 'qa', 'exclude-files' => ['vendor/*']],
                ],
            ],
            ['qa'],
            [],
            $result
        );

        $this->assertFalse($result->hasErrors());
        $refs = $hooks->resolve('pre-commit');
        $this->assertEquals([], $refs[0]->getOnlyFiles());
        $this->assertEquals(['vendor/*'], $refs[0]->getExcludeFiles());
        $this->assertTrue($refs[0]->hasConditions());
    }

    /** @test */
    public function it_mixes_string_and_array_refs()
    {
        $result = new ValidationResult();
        $hooks = HookConfiguration::fromArray(
            [
                'pre-commit' => [
                    'qa',
                    ['flow' => 'heavy', 'only-on' => ['main']],
                ],
            ],
            ['qa', 'heavy'],
            [],
            $result
        );

        $this->assertFalse($result->hasErrors());
        $refs = $hooks->resolve('pre-commit');
        $this->assertCount(2, $refs);
        $this->assertFalse($refs[0]->hasConditions());
        $this->assertTrue($refs[1]->hasConditions());
    }

    /** @test */
    public function it_uses_default_command_when_not_specified()
    {
        $result = new ValidationResult();
        $hooks = HookConfiguration::fromArray(
            ['pre-commit' => ['qa']],
            ['qa'],
            [],
            $result
        );

        $this->assertEquals('php vendor/bin/githooks', $hooks->getCommand());
    }

    /** @test */
    public function it_reads_custom_command_from_config()
    {
        $result = new ValidationResult();
        $hooks = HookConfiguration::fromArray(
            [
                'command' => 'php7.4 vendor/bin/githooks',
                'pre-commit' => ['qa'],
            ],
            ['qa'],
            [],
            $result
        );

        $this->assertFalse($result->hasErrors());
        $this->assertEquals('php7.4 vendor/bin/githooks', $hooks->getCommand());
        // 'command' should not be treated as a hook event
        $this->assertCount(1, $hooks->getEvents());
        $this->assertEquals(['pre-commit'], $hooks->getEvents());
    }

    /** @test */
    public function it_reports_error_for_non_string_command()
    {
        $result = new ValidationResult();
        HookConfiguration::fromArray(
            [
                'command' => 123,
                'pre-commit' => ['qa'],
            ],
            ['qa'],
            [],
            $result
        );

        $this->assertTrue($result->hasErrors());
        $this->assertStringContainsString('hooks.command', $result->getErrors()[0]);
    }

    /**
     * @param string[] $expected
     * @param HookRef[] $refs
     */
    private function assertTargets(array $expected, array $refs): void
    {
        $targets = array_map(function (HookRef $ref): string {
            return $ref->getTarget();
        }, $refs);
        $this->assertEquals($expected, $targets);
    }
}
