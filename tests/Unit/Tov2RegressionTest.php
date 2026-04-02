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
     * Bug 1: The {-c|--config=} format is invalid Symfony Console syntax.
     * Symfony interprets -c as a positional argument, not an option shortcut.
     * In PHP 8.1+ with Symfony 6+, it causes a fatal error at command registration.
     */
    function execute_tool_command_does_not_define_c_shortcut_for_config_option()
    {
        $rc = new \ReflectionClass(ExecuteToolCommand::class);
        $defaults = $rc->getDefaultProperties();

        $this->assertNotFalse(
            strpos($defaults['signature'], '--config='),
            'ExecuteToolCommand should define the --config option'
        );
        $this->assertFalse(
            strpos($defaults['signature'], '-c|') !== false,
            'ExecuteToolCommand should not contain -c shortcut (invalid Symfony Console syntax)'
        );
    }

    /**
     * @test
     * Bug 1: Same issue in CheckConfigurationFileCommand.
     */
    function check_configuration_file_command_does_not_define_c_shortcut_for_config_option()
    {
        $rc = new \ReflectionClass(CheckConfigurationFileCommand::class);
        $defaults = $rc->getDefaultProperties();

        $this->assertNotFalse(
            strpos($defaults['signature'], '--config='),
            'CheckConfigurationFileCommand should define the --config option'
        );
        $this->assertFalse(
            strpos($defaults['signature'], '-c|') !== false,
            'CheckConfigurationFileCommand should not contain -c shortcut (invalid Symfony Console syntax)'
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
