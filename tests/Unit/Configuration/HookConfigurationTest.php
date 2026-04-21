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
    public function it_parses_exclude_on_condition()
    {
        $result = new ValidationResult();
        $hooks = HookConfiguration::fromArray(
            [
                'pre-commit' => [
                    ['flow' => 'qa', 'exclude-on' => ['GH-*', 'temp/*']],
                ],
            ],
            ['qa'],
            [],
            $result
        );

        $this->assertFalse($result->hasErrors());
        $refs = $hooks->resolve('pre-commit');
        $this->assertCount(1, $refs);
        $this->assertEquals(['GH-*', 'temp/*'], $refs[0]->getExcludeOnBranches());
        $this->assertTrue($refs[0]->hasConditions());
    }

    /** @test */
    public function it_parses_only_on_with_exclude_on()
    {
        $result = new ValidationResult();
        $hooks = HookConfiguration::fromArray(
            [
                'pre-push' => [
                    ['flow' => 'deploy', 'only-on' => ['release/*'], 'exclude-on' => ['release/beta-*']],
                ],
            ],
            ['deploy'],
            [],
            $result
        );

        $this->assertFalse($result->hasErrors());
        $refs = $hooks->resolve('pre-push');
        $this->assertEquals(['release/*'], $refs[0]->getOnlyOnBranches());
        $this->assertEquals(['release/beta-*'], $refs[0]->getExcludeOnBranches());
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

    // ========================================================================
    // HookRef execution mode (TDD — will fail until implementation exists)
    // ========================================================================

    /** @test */
    public function it_parses_hookref_with_fast_execution()
    {
        $result = new ValidationResult();
        $hooks = HookConfiguration::fromArray(
            [
                'pre-commit' => [
                    ['flow' => 'qa', 'execution' => 'fast'],
                ],
            ],
            ['qa'],
            [],
            $result
        );

        $this->assertFalse($result->hasErrors());
        $refs = $hooks->resolve('pre-commit');
        $this->assertEquals('fast', $refs[0]->getExecution());
    }

    /** @test */
    public function it_parses_hookref_with_fast_branch_execution()
    {
        $result = new ValidationResult();
        $hooks = HookConfiguration::fromArray(
            [
                'pre-commit' => [
                    ['flow' => 'qa', 'execution' => 'fast-branch'],
                ],
            ],
            ['qa'],
            [],
            $result
        );

        $this->assertFalse($result->hasErrors());
        $refs = $hooks->resolve('pre-commit');
        $this->assertEquals('fast-branch', $refs[0]->getExecution());
    }

    /** @test */
    public function hookref_without_execution_returns_null()
    {
        $result = new ValidationResult();
        $hooks = HookConfiguration::fromArray(
            ['pre-commit' => [['flow' => 'qa']]],
            ['qa'],
            [],
            $result
        );

        $refs = $hooks->resolve('pre-commit');
        $this->assertNull($refs[0]->getExecution());
    }

    /** @test */
    public function hookref_execution_does_not_trigger_unknown_key_warning()
    {
        $result = new ValidationResult();
        HookConfiguration::fromArray(
            [
                'pre-commit' => [
                    ['flow' => 'qa', 'execution' => 'fast'],
                ],
            ],
            ['qa'],
            [],
            $result
        );

        $executionWarnings = array_filter($result->getWarnings(), function (string $w) {
            return strpos($w, 'execution') !== false;
        });
        $this->assertEmpty($executionWarnings);
    }

    /** @test */
    public function string_hookref_has_null_execution()
    {
        $result = new ValidationResult();
        $hooks = HookConfiguration::fromArray(
            ['pre-commit' => ['qa']],
            ['qa'],
            [],
            $result
        );

        $this->assertFalse($result->hasErrors());
        $refs = $hooks->resolve('pre-commit');
        $this->assertNull($refs[0]->getExecution());
    }

    /** @test */
    public function hookref_with_execution_and_conditions_works()
    {
        $result = new ValidationResult();
        $hooks = HookConfiguration::fromArray(
            [
                'pre-commit' => [
                    [
                        'flow'      => 'qa',
                        'execution' => 'fast-branch',
                        'only-on'   => ['main'],
                    ],
                ],
            ],
            ['qa'],
            [],
            $result
        );

        $this->assertFalse($result->hasErrors());
        $refs = $hooks->resolve('pre-commit');
        $this->assertEquals('fast-branch', $refs[0]->getExecution());
        $this->assertEquals(['main'], $refs[0]->getOnlyOnBranches());
        $this->assertTrue($refs[0]->hasConditions());
    }

    /**
     * @test
     * Kills L57 Continue_→break: with two events, the first invalid (not a git
     * hook) and the second valid, `break` would short-circuit the foreach and
     * drop the second event's configuration.
     */
    public function it_keeps_validating_events_after_an_invalid_event_name()
    {
        $result = new ValidationResult();
        $hooks = HookConfiguration::fromArray(
            [
                'not-a-real-hook' => ['lint'],
                'pre-commit'      => ['qa'],
            ],
            ['lint', 'qa'],
            [],
            $result
        );

        $this->assertTrue($result->hasErrors());
        $this->assertTargets(['qa'], $hooks->resolve('pre-commit'));
        $this->assertSame(['pre-commit'], $hooks->getEvents());
    }

    /**
     * @test
     * Kills L62 Continue_→break: two events, first with an empty refs array and
     * the second valid. `break` after the first error drops the second hook.
     */
    public function it_keeps_validating_events_after_an_empty_refs_array()
    {
        $result = new ValidationResult();
        $hooks = HookConfiguration::fromArray(
            [
                'pre-commit' => [],
                'pre-push'   => ['qa'],
            ],
            ['qa'],
            [],
            $result
        );

        $this->assertTrue($result->hasErrors());
        $this->assertTargets(['qa'], $hooks->resolve('pre-push'));
        $this->assertSame(['pre-push'], $hooks->getEvents());
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
