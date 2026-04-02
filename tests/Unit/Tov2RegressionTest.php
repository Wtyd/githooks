<?php

namespace Tests\Unit;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\App\Commands\CheckConfigurationFileCommand;
use Wtyd\GitHooks\App\Commands\ExecuteToolCommand;

/**
 * Regression tests for bugs documented in Tov2.md.
 * These bugs were found during v3.0.0 development and backported to v2.8.x.
 */
class Tov2RegressionTest extends UnitTestCase
{
    /**
     * @test
     * Bug 1: The original {-c|--config=} format is invalid Symfony Console syntax.
     * The correct format is {--c|config=} which defines -c as shortcut for --config.
     */
    function execute_tool_command_defines_c_shortcut_with_correct_syntax()
    {
        $rc = new \ReflectionClass(ExecuteToolCommand::class);
        $defaults = $rc->getDefaultProperties();

        $this->assertNotFalse(
            strpos($defaults['signature'], '--c|config='),
            'ExecuteToolCommand should define -c shortcut with correct syntax {--c|config=}'
        );
        $this->assertFalse(
            strpos($defaults['signature'], '{-c|') !== false,
            'ExecuteToolCommand must not use invalid {-c|...} syntax'
        );
    }

    /**
     * @test
     * Bug 1: Same issue in CheckConfigurationFileCommand.
     */
    function check_configuration_file_command_defines_c_shortcut_with_correct_syntax()
    {
        $rc = new \ReflectionClass(CheckConfigurationFileCommand::class);
        $defaults = $rc->getDefaultProperties();

        $this->assertNotFalse(
            strpos($defaults['signature'], '--c|config='),
            'CheckConfigurationFileCommand should define -c shortcut with correct syntax {--c|config=}'
        );
        $this->assertFalse(
            strpos($defaults['signature'], '{-c|') !== false,
            'CheckConfigurationFileCommand must not use invalid {-c|...} syntax'
        );
    }

    /**
     * @test
     * Bug 2: The MocksApplicationServices trait was deprecated and removed in modern Laravel.
     * It causes a fatal error when loading IlluminateTestCase on PHP 8.1+ with
     * laravel-zero/foundation versions that no longer include this trait.
     * The trait is never used in any test of the project.
     */
    function illuminate_test_case_does_not_use_deprecated_mocks_application_services_trait()
    {
        $rc = new \ReflectionClass(\Tests\Zero\IlluminateTestCase::class);
        $content = file_get_contents($rc->getFileName());

        $this->assertFalse(
            strpos($content, 'MocksApplicationServices') !== false,
            'IlluminateTestCase should not use MocksApplicationServices (removed in modern Laravel, causes fatal error on PHP 8.1+)'
        );
    }

    /**
     * @test
     * Bug 3: hooks/default.php must re-stage files after auto-fixing tools (phpcbf) modify them.
     * Without re-staging, the commit contains the pre-fix version and the fixes are left as
     * unstaged changes that the user might not notice.
     */
    function hooks_default_restages_files_after_successful_tool_execution()
    {
        $hookPath = realpath(__DIR__ . '/../../hooks/default.php');
        $this->assertNotFalse($hookPath, 'hooks/default.php should exist');

        $content = file_get_contents($hookPath);

        $this->assertNotFalse(
            strpos($content, 'git add'),
            'hooks/default.php should re-stage files modified by auto-fixing tools after successful execution'
        );
        $this->assertNotFalse(
            strpos($content, 'git diff --cached'),
            'hooks/default.php should identify staged files via git diff --cached before re-staging'
        );
    }
}
