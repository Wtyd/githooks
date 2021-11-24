<?php

namespace Tests\Unit\Tools\Tool\CodeSniffer;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;
use Wtyd\GitHooks\Tools\Tool\CodeSniffer\Phpcs;
use Wtyd\GitHooks\Tools\Tool\CodeSniffer\PhpcsFake;

class PhpcsTest extends TestCase
{
    /** @test */
    function phpcs_is_a_supported_tool()
    {
        $this->assertTrue(Phpcs::checkTool('phpcs'));
    }

    /** @test */
    function set_all_arguments_from_configuration_file()
    {
        $configuration = [
            'executablePath' => 'path/tools/phpcs',
            'standard' => 'PSR12',
            'ignore' => ['vendor'],
            'error-severity' => 1,
            'warning-severity' => 6
        ];

        $toolConfiguration = new ToolConfiguration('phpcs', $configuration);

        $phpcs = new PhpcsFake($toolConfiguration);

        $this->assertEquals($configuration['executablePath'], $phpcs->getExecutablePath());

        $this->assertEquals($configuration, $phpcs->getArguments());
    }

    /** @test */
    function it_sets_phpcs_in_executablePath_when_is_empty()
    {
        $configuration = [
            'standard' => 'PSR12',
            'ignore' => ['vendor'],
            'error-severity' => 1,
            'warning-severity' => 6,
            'otherArguments' => '--report=summary --parallel=2'
        ];

        $toolConfiguration = new ToolConfiguration('phpcs', $configuration);

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
            'unexpected or supported argument' => 'my value'
        ];

        $toolConfiguration = new ToolConfiguration('phpcs', $configuration);

        $phpcs = new PhpcsFake($toolConfiguration);

        $this->assertEquals($configuration['executablePath'], $phpcs->getExecutablePath());

        unset($configuration['unexpected or supported argument']);
        $this->assertEquals($configuration, $phpcs->getArguments());
    }


    /** @test */
    function it_needs_mandatory_arguments()
    {
        $this->markTestIncomplete('This funcionalitity is not developed yet');
        $configuration = [
            'executablePath' => 'path/tools/phpcs',
            // 'standard' => 'PSR12',
            // 'ignore' => ['vendor'],
            // 'error-severity' => 1,
            // 'warning-severity' => 6,
            // 'unexpected or supported argument' => 'my value'
        ];

        $toolConfiguration = new ToolConfiguration('phpcs', $configuration);

        $phpcs = new PhpcsFake($toolConfiguration);

        $this->assertEquals($configuration['executablePath'], $phpcs->getExecutablePath());

        unset($configuration['executablePath']);
        $this->assertEquals($configuration, $phpcs->getArguments());
    }

    /** @test */
    function it_checks_type_and_posible_values_for_every_argument()
    {
        $this->markTestIncomplete('This funcionalitity is not developed yet');
        $configuration = [
            'executablePath' => 'path/tools/phpcs',
            // 'standard' => 'PSR12',
            // 'ignore' => ['vendor'],
            // 'error-severity' => 1,
            // 'warning-severity' => 6,
            // 'unexpected or supported argument' => 'my value'
        ];

        $toolConfiguration = new ToolConfiguration('phpcs', $configuration);

        $phpcs = new PhpcsFake($toolConfiguration);

        $this->assertEquals($configuration['executablePath'], $phpcs->getExecutablePath());

        unset($configuration['executablePath']);
        $this->assertEquals($configuration, $phpcs->getArguments());
    }
}
