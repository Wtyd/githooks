<?php

namespace Tests\Unit\Tools\Tool\CodeSniffer;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;
use Wtyd\GitHooks\Tools\Tool\CodeSniffer\PhpcbfFake;
use Wtyd\GitHooks\Tools\Tool\CodeSniffer\Phpcs;

class PhpcbfTest extends TestCase
{
    /** @test */
    function phpcs_is_a_supported_tool()
    {
        $this->assertTrue(Phpcs::checkTool('phpcbf'));
    }

    /** @test */
    function set_all_arguments_of_phpcbf_from_configuration_file()
    {
        $configuration = [
            'executablePath' => 'path/tools/phpcbf',
            'paths' => ['src'],
            'standard' => 'PSR12',
            'ignore' => ['vendor'],
            'error-severity' => 1,
            'warning-severity' => 6,
            'otherArguments' => '--report=summary --parallel=2'
        ];

        $toolConfiguration = new ToolConfiguration('phpcbf', $configuration);

        $phpcbf = new PhpcbfFake($toolConfiguration);

        $this->assertEquals($configuration['executablePath'], $phpcbf->getExecutablePath());

        $this->assertEquals($configuration, $phpcbf->getArguments());

        $this->assertCount(count(PhpcbfFake::OPTIONS), $phpcbf->getArguments());
    }

    /** @test */
    function it_sets_phpcbf_in_executablePath_when_is_empty()
    {
        $configuration = [
            'standard' => 'PSR12',
            'ignore' => ['vendor'],
            'error-severity' => 1,
            'warning-severity' => 6,
            'otherArguments' => '--report=summary --parallel=2'
        ];

        $toolConfiguration = new ToolConfiguration('phpcbf', $configuration);

        $phpcbf = new PhpcbfFake($toolConfiguration);

        $this->assertEquals('phpcbf', $phpcbf->getExecutablePath());
    }

    /** @test */
    function it_ignores_unexpected_arguments()
    {
        $configuration = [
            'executablePath' => 'path/tools/phpcbf',
            'standard' => 'PSR12',
            'ignore' => ['vendor'],
            'error-severity' => 1,
            'warning-severity' => 6,
            'otherArguments' => '--report=summary --parallel=2',
            'unexpected or supported argument' => 'my value'
        ];

        $toolConfiguration = new ToolConfiguration('phpcbf', $configuration);

        $phpcbf = new PhpcbfFake($toolConfiguration);

        $this->assertEquals($configuration['executablePath'], $phpcbf->getExecutablePath());

        unset($configuration['unexpected or supported argument']);
        $this->assertEquals($configuration, $phpcbf->getArguments());
    }
    /** @test */
    function it_replaces_phpcs_for_phpcbf_in_executablePath()
    {
        $configuration = [
            'executablePath' => 'path/tools/phpcs',
            'standard' => 'PSR12',
            'ignore' => ['vendor'],
            'error-severity' => 1,
            'warning-severity' => 6,
            'otherArguments' => '--report=summary --parallel=2',
        ];

        $toolConfiguration = new ToolConfiguration('phpcbf', $configuration);

        $phpcbf = new PhpcbfFake($toolConfiguration);

        $this->assertEquals('path/tools/phpcbf', $phpcbf->getArguments()['executablePath']);
        $configuration['executablePath'] = 'path/tools/phpcbf';
        $this->assertEquals($configuration, $phpcbf->getArguments());
    }
}
