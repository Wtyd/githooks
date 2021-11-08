<?php

namespace Tests\Unit\Tools\Tool\CodeSniffer;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;
use Wtyd\GitHooks\Tools\Tool\CodeSniffer\Phpcs;
use Wtyd\GitHooks\Tools\Tool\CodeSniffer\PhpcsFake;

class PhpcbfTest extends TestCase
{
    /** @test */
    function phpcs_is_a_supported_tool()
    {
        $this->assertTrue(Phpcs::checkTool('phpcbf'));
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

        $toolConfiguration = new ToolConfiguration('phpcbf', $configuration);

        $phpcs = new PhpcsFake($toolConfiguration);

        $this->assertEquals($configuration['executablePath'], $phpcs->getExecutablePath());

        unset($configuration['executablePath']);
        $this->assertEquals($configuration, $phpcs->getArguments());
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

        unset($configuration['executablePath']);
        unset($configuration['unexpected or supported argument']);
        $this->assertEquals($configuration, $phpcs->getArguments());
    }
}
