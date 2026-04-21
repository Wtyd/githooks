<?php

namespace Tests\Unit\ConfigurationFile;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;
use Wtyd\GitHooks\Registry\ToolRegistry;
use Wtyd\GitHooks\Tools\Tool\ToolAbstract;

/**
 * Direct coverage for ToolConfiguration. Infection report 2026-04-20 — L184.
 */
class ToolConfigurationTest extends UnitTestCase
{
    /** @test */
    function it_warns_with_exact_message_when_failFast_and_ignoreErrorsOnExit_are_both_true()
    {
        // Mutants L184 Concat / ConcatOperandRemoval: removing the `.` or either side
        // of the concatenated sentence. Only an exact-string match can kill them —
        // `assertStringContainsString` on 'failFast' would let the mutant survive.
        $toolConfig = new ToolConfiguration('phpstan', [
            'paths'              => ['src'],
            ToolAbstract::FAIL_FAST            => true,
            ToolAbstract::IGNORE_ERRORS_ON_EXIT => true,
        ], new ToolRegistry());

        $expected = "Tool phpstan has both 'failFast' and 'ignoreErrorsOnExit' set to true. "
            . "'failFast' takes priority — 'ignoreErrorsOnExit' will be ignored.";

        $this->assertContains($expected, $toolConfig->getWarnings());
    }

    /** @test */
    function it_forces_ignoreErrorsOnExit_to_false_when_failFast_wins()
    {
        $toolConfig = new ToolConfiguration('phpstan', [
            'paths'                              => ['src'],
            ToolAbstract::FAIL_FAST               => true,
            ToolAbstract::IGNORE_ERRORS_ON_EXIT  => true,
        ], new ToolRegistry());

        $effective = $toolConfig->getToolConfiguration();

        $this->assertTrue($effective[ToolAbstract::FAIL_FAST]);
        $this->assertFalse($effective[ToolAbstract::IGNORE_ERRORS_ON_EXIT]);
    }

    /** @test */
    function it_does_not_emit_conflict_warning_when_only_one_flag_is_true()
    {
        $onlyFailFast = new ToolConfiguration('phpstan', [
            'paths'                 => ['src'],
            ToolAbstract::FAIL_FAST  => true,
        ], new ToolRegistry());

        $onlyIgnore = new ToolConfiguration('phpstan', [
            'paths'                              => ['src'],
            ToolAbstract::IGNORE_ERRORS_ON_EXIT  => true,
        ], new ToolRegistry());

        $conflict = "Tool phpstan has both 'failFast' and 'ignoreErrorsOnExit' set to true. "
            . "'failFast' takes priority — 'ignoreErrorsOnExit' will be ignored.";

        $this->assertNotContains($conflict, $onlyFailFast->getWarnings());
        $this->assertNotContains($conflict, $onlyIgnore->getWarnings());
    }

    /** @test */
    function it_reports_empty_warnings_with_valid_configuration()
    {
        $toolConfig = new ToolConfiguration('phpstan', [
            'paths' => ['src'],
            'level' => 8,
        ], new ToolRegistry());

        $this->assertTrue($toolConfig->isEmptyWarnings());
        $this->assertSame([], $toolConfig->getWarnings());
    }

    /** @test */
    function it_warns_with_exact_message_when_argument_is_invalid()
    {
        $toolConfig = new ToolConfiguration('phpstan', [
            'paths'      => ['src'],
            'unknownArg' => 'whatever',
        ], new ToolRegistry());

        $this->assertContains(
            'unknownArg argument is invalid for tool phpstan. It will be ignored.',
            $toolConfig->getWarnings()
        );
    }

    /** @test */
    function it_strips_invalid_arguments_from_configuration()
    {
        $toolConfig = new ToolConfiguration('phpstan', [
            'paths'      => ['src'],
            'unknownArg' => 'whatever',
        ], new ToolRegistry());

        $this->assertArrayNotHasKey('unknownArg', $toolConfig->getToolConfiguration());
    }

    /** @test */
    function it_warns_with_exact_message_when_failFast_is_not_boolean()
    {
        $toolConfig = new ToolConfiguration('phpstan', [
            'paths'                 => ['src'],
            ToolAbstract::FAIL_FAST  => 'yes',
        ], new ToolRegistry());

        $this->assertContains(
            "Value for '" . ToolAbstract::FAIL_FAST . "' in tool phpstan must be boolean. This option will be ignored.",
            $toolConfig->getWarnings()
        );
        $this->assertFalse($toolConfig->getToolConfiguration()[ToolAbstract::FAIL_FAST]);
    }

    /** @test */
    function it_warns_with_exact_message_when_ignoreErrorsOnExit_is_not_boolean()
    {
        $toolConfig = new ToolConfiguration('phpstan', [
            'paths'                              => ['src'],
            ToolAbstract::IGNORE_ERRORS_ON_EXIT  => 1,
        ], new ToolRegistry());

        $this->assertContains(
            "Value for'" . ToolAbstract::IGNORE_ERRORS_ON_EXIT . "'in tool phpstan must be boolean. This option will be ignored.",
            $toolConfig->getWarnings()
        );
        $this->assertFalse($toolConfig->getToolConfiguration()[ToolAbstract::IGNORE_ERRORS_ON_EXIT]);
    }

    /** @test */
    function it_extracts_and_validates_per_tool_execution_mode()
    {
        $toolConfig = new ToolConfiguration('phpstan', [
            'paths'     => ['src'],
            'execution' => 'fast',
        ], new ToolRegistry());

        $this->assertTrue($toolConfig->hasExecution());
        $this->assertSame('fast', $toolConfig->getExecution());
        $this->assertArrayNotHasKey('execution', $toolConfig->getToolConfiguration());
    }

    /** @test */
    function it_warns_when_execution_value_is_invalid()
    {
        $toolConfig = new ToolConfiguration('phpstan', [
            'paths'     => ['src'],
            'execution' => 'weird',
        ], new ToolRegistry());

        $this->assertFalse($toolConfig->hasExecution());
        $this->assertNull($toolConfig->getExecution());

        $hasMatchingWarning = false;
        foreach ($toolConfig->getWarnings() as $warning) {
            if (strpos($warning, "Value 'weird' for 'execution' in tool phpstan is not valid") === 0) {
                $hasMatchingWarning = true;
                break;
            }
        }
        $this->assertTrue($hasMatchingWarning, 'Expected execution invalid-value warning not found');
    }

    /** @test */
    function it_returns_the_tool_name_passed_at_construction()
    {
        $toolConfig = new ToolConfiguration('phpmd', ['paths' => ['src']], new ToolRegistry());

        $this->assertSame('phpmd', $toolConfig->getTool());
    }

    /** @test */
    function it_exposes_paths_from_configuration()
    {
        $toolConfig = new ToolConfiguration('phpstan', ['paths' => ['src', 'app']], new ToolRegistry());

        $this->assertSame(['src', 'app'], $toolConfig->getPaths());
    }

    /** @test */
    function it_returns_empty_paths_when_configuration_has_none()
    {
        $toolConfig = new ToolConfiguration('phpstan', ['level' => 8], new ToolRegistry());

        $this->assertSame([], $toolConfig->getPaths());
    }

    /** @test */
    function set_paths_overrides_the_paths_in_configuration()
    {
        $toolConfig = new ToolConfiguration('phpstan', ['paths' => ['src']], new ToolRegistry());

        $toolConfig->setPaths(['src/Foo.php', 'src/Bar.php']);

        $this->assertSame(['src/Foo.php', 'src/Bar.php'], $toolConfig->getPaths());
    }

    /** @test */
    function is_empty_warnings_returns_false_after_conflict()
    {
        $toolConfig = new ToolConfiguration('phpstan', [
            'paths'                              => ['src'],
            ToolAbstract::FAIL_FAST               => true,
            ToolAbstract::IGNORE_ERRORS_ON_EXIT  => true,
        ], new ToolRegistry());

        $this->assertFalse($toolConfig->isEmptyWarnings());
    }
}
