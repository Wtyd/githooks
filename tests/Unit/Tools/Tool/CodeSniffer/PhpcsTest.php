<?php

namespace Tests\Unit\Tools\Tool\CodeSniffer;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;
use Wtyd\GitHooks\Tools\Tool\CodeSniffer\Phpcs;
use Tests\Doubles\PhpcsFake;
use Wtyd\GitHooks\Registry\ToolRegistry;

class PhpcsTest extends UnitTestCase
{
    /** @test */
    function phpcs_is_a_supported_tool()
    {
        $this->assertTrue((new ToolRegistry())->isSupported('phpcs'));
    }

    /** @test */
    function set_all_arguments_of_phpcs_from_configuration_file()
    {
        $configuration = [
            'executablePath' => 'path/tools/phpcs',
            'paths' => ['src'],
            'standard' => 'PSR12',
            'ignore' => ['vendor'],
            'error-severity' => 1,
            'warning-severity' => 6,
            'cache' => true,
            'no-cache' => false,
            'report' => 'summary',
            'parallel' => 2,
            'otherArguments' => '--colors',
            'ignoreErrorsOnExit' => true,
            'failFast' => false,
        ];

        $toolConfiguration = new ToolConfiguration('phpcs', $configuration, new ToolRegistry());

        $phpcs = new PhpcsFake($toolConfiguration);

        $this->assertEquals($configuration['executablePath'], $phpcs->getExecutablePath());

        $this->assertEquals($configuration, $phpcs->getArguments());

        $this->assertCount(count(PhpcsFake::ARGUMENTS), $phpcs->getArguments());
    }

    /** @test */
    function it_sets_phpcs_in_executablePath_when_is_empty()
    {
        $configuration = [
            'standard' => 'PSR12',
            'ignore' => ['vendor'],
            'error-severity' => 1,
            'warning-severity' => 6,
            'cache' => true,
            'report' => 'summary',
            'parallel' => 2,
            'otherArguments' => '--colors',
            'ignoreErrorsOnExit' => true,
        ];

        $toolConfiguration = new ToolConfiguration('phpcs', $configuration, new ToolRegistry());

        $phpcs = new PhpcsFake($toolConfiguration);

        $this->assertEquals('phpcs', $phpcs->getExecutablePath());
    }

    /** @test */
    function it_ignores_unexpected_arguments()
    {
        $configuration = [
            'executablePath' => 'path/tools/phpcs',
            'standard' => 'PSR12',
            'ignore' => ['vendor'],
            'error-severity' => 1,
            'warning-severity' => 6,
            'cache' => true,
            'report' => 'summary',
            'parallel' => 2,
            'ignoreErrorsOnExit' => true,
            'unexpected or supported argument' => 'my value'
        ];

        $toolConfiguration = new ToolConfiguration('phpcs', $configuration, new ToolRegistry());

        $phpcs = new PhpcsFake($toolConfiguration);

        $this->assertEquals($configuration['executablePath'], $phpcs->getExecutablePath());

        unset($configuration['unexpected or supported argument']);
        $this->assertEquals($configuration, $phpcs->getArguments());
    }

    /** @test */
    function it_prepares_phpcs_command_with_cache_report_and_parallel()
    {
        $configuration = [
            'executablePath' => 'path/tools/phpcs',
            'paths' => ['src'],
            'standard' => 'PSR12',
            'ignore' => ['vendor'],
            'error-severity' => 1,
            'warning-severity' => 6,
            'cache' => true,
            'report' => 'summary',
            'parallel' => 2,
        ];

        $toolConfiguration = new ToolConfiguration('phpcs', $configuration, new ToolRegistry());
        $phpcs = new PhpcsFake($toolConfiguration);

        $cmd = $phpcs->prepareCommand();
        $this->assertStringContainsString('--standard=PSR12', $cmd);
        $this->assertStringContainsString('--error-severity=1', $cmd);
        $this->assertStringContainsString('--warning-severity=6', $cmd);
        $this->assertStringContainsString('--cache', $cmd);
        $this->assertStringContainsString('--report=summary', $cmd);
        $this->assertStringContainsString('--parallel=2', $cmd);
    }

    /** @test */
    function it_sets_phpcs_to_run_against_and_ignore_several_paths()
    {
        $configuration = [
            'executablePath' => 'path/tools/phpcs',
            'paths' => ['src', 'tests'],
            'standard' => 'PSR12',
            'ignore' => ['vendor', 'app'],
        ];

        $toolConfiguration = new ToolConfiguration('phpcs', $configuration, new ToolRegistry());

        $phpcs = new PhpcsFake($toolConfiguration);

        $this->assertStringContainsString('src tests', $phpcs->prepareCommand());
        $this->assertStringContainsString('--ignore=vendor,app', $phpcs->prepareCommand());
    }
}
