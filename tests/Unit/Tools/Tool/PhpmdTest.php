<?php

namespace Tests\Unit\Tools\Tool;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;
use Wtyd\GitHooks\Tools\Tool\Phpmd;
use Wtyd\GitHooks\Tools\Tool\PhpmdFake;

class PhpmdTest extends TestCase
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
            'unexpected or supported argument' => 'my value'
        ];

        $toolConfiguration = new ToolConfiguration('phpmd', $configuration);

        $phpmd = new PhpmdFake($toolConfiguration);

        $this->assertEquals($configuration['executablePath'], $phpmd->getExecutablePath());

        unset($configuration['unexpected or supported argument']);
        $this->assertEquals($configuration, $phpmd->getArguments());
    }
}