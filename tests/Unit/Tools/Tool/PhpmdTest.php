<?php

namespace Tests\Unit\Tools\Tool;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;
use Wtyd\GitHooks\Tools\Tool\Phpmd;
use Wtyd\GitHooks\Tools\Tool\PhpmdFake;

class PhpmdTest extends UnitTestCase
{
    /** @test */
    function phpmd_is_a_supported_tool()
    {
        $this->assertTrue(Phpmd::checkTool('phpmd'));
    }

    /** @test */
    function set_all_arguments_of_phpmd_from_configuration_file()
    {
        $configuration = [
            'executablePath' => 'path/tools/phpmd',
            'paths' => ['src'],
            'rules' => 'unusedcode',
            'exclude' => ['vendor'],
            'otherArguments' => '--strict',
            'ignoreErrorsOnExit' => true,
        ];

        $toolConfiguration = new ToolConfiguration('phpmd', $configuration);

        $phpmd = new PhpmdFake($toolConfiguration);

        $this->assertEquals($configuration['executablePath'], $phpmd->getExecutablePath());

        $this->assertEquals($configuration, $phpmd->getArguments());

        $this->assertCount(count(PhpmdFake::ARGUMENTS), $phpmd->getArguments());
    }

    /** @test */
    function it_sets_phpmd_in_executablePath_when_is_empty()
    {
        $configuration = [
            'paths' => ['src'],
            'rules' => 'unusedcode',
            'exclude' => ['vendor'],
            'otherArguments' => '--strict',
            'ignoreErrorsOnExit' => true,
        ];

        $toolConfiguration = new ToolConfiguration('phpmd', $configuration);

        $phpmd = new PhpmdFake($toolConfiguration);

        $this->assertEquals('phpmd', $phpmd->getExecutablePath());
    }

    /** @test */
    function it_ignores_unexpected_arguments()
    {
        $configuration = [
            'paths' => ['src'],
            'executablePath' => 'path/tools/phpmd',
            'rules' => 'unusedcode',
            'exclude' => ['vendor'],
            'otherArguments' => '--strict',
            'ignoreErrorsOnExit' => true,
            'unexpected or supported argument' => 'my value'
        ];

        $toolConfiguration = new ToolConfiguration('phpmd', $configuration);

        $phpmd = new PhpmdFake($toolConfiguration);

        $this->assertEquals($configuration['executablePath'], $phpmd->getExecutablePath());

        unset($configuration['unexpected or supported argument']);
        $this->assertEquals($configuration, $phpmd->getArguments());
    }

    /** @test */
    function it_sets_phpmd_to_run_against_and_ignore_several_paths()
    {
        $configuration = [
            'executablePath' => 'path/tools/phpmd',
            'paths' => ['src', 'tests'],
            'rules' => 'unusedcode',
            'exclude' => ['vendor', 'app'],
        ];

        $toolConfiguration = new ToolConfiguration('phpmd', $configuration);

        $phpmd = new PhpmdFake($toolConfiguration);

        $this->assertStringContainsString('src,tests', $phpmd->prepareCommand());
        $this->assertStringContainsString('--exclude "vendor,app"', $phpmd->prepareCommand());
    }
}
