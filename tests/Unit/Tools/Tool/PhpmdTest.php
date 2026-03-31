<?php

namespace Tests\Unit\Tools\Tool;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;
use Wtyd\GitHooks\Tools\Tool\Phpmd;
use Tests\Doubles\PhpmdFake;
use Wtyd\GitHooks\Registry\ToolRegistry;

class PhpmdTest extends UnitTestCase
{
    /** @test */
    function phpmd_is_a_supported_tool()
    {
        $this->assertTrue((new ToolRegistry())->isSupported('phpmd'));
    }

    /** @test */
    function set_all_arguments_of_phpmd_from_configuration_file()
    {
        $configuration = [
            'executablePath' => 'path/tools/phpmd',
            'paths' => ['src'],
            'rules' => 'unusedcode',
            'exclude' => ['vendor'],
            'cache' => true,
            'cache-file' => '.phpmd.cache',
            'cache-strategy' => 'content',
            'suffixes' => 'php,inc',
            'baseline-file' => 'phpmd-baseline.xml',
            'otherArguments' => '--strict',
            'ignoreErrorsOnExit' => true,
            'failFast' => false,
        ];

        $toolConfiguration = new ToolConfiguration('phpmd', $configuration, new ToolRegistry());

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
            'cache' => true,
            'otherArguments' => '--strict',
            'ignoreErrorsOnExit' => true,
        ];

        $toolConfiguration = new ToolConfiguration('phpmd', $configuration, new ToolRegistry());

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
            'cache' => true,
            'cache-file' => '.phpmd.cache',
            'cache-strategy' => 'content',
            'suffixes' => 'php,inc',
            'baseline-file' => 'phpmd-baseline.xml',
            'otherArguments' => '--strict',
            'ignoreErrorsOnExit' => true,
            'unexpected or supported argument' => 'my value'
        ];

        $toolConfiguration = new ToolConfiguration('phpmd', $configuration, new ToolRegistry());

        $phpmd = new PhpmdFake($toolConfiguration);

        $this->assertEquals($configuration['executablePath'], $phpmd->getExecutablePath());

        unset($configuration['unexpected or supported argument']);
        $this->assertEquals($configuration, $phpmd->getArguments());
    }

    /** @test */
    function it_prepares_phpmd_command_with_cache_and_all_arguments()
    {
        $configuration = [
            'executablePath' => 'vendor/bin/phpmd',
            'paths' => ['src'],
            'rules' => 'cleancode,codesize',
            'exclude' => ['vendor'],
            'cache' => true,
            'cache-file' => '.phpmd.cache',
            'cache-strategy' => 'content',
            'suffixes' => 'php,inc',
            'baseline-file' => 'phpmd-baseline.xml',
            'otherArguments' => '--strict',
        ];

        $toolConfiguration = new ToolConfiguration('phpmd', $configuration, new ToolRegistry());
        $phpmd = new PhpmdFake($toolConfiguration);

        $cmd = $phpmd->prepareCommand();
        $this->assertStringContainsString('vendor/bin/phpmd', $cmd);
        $this->assertStringContainsString('src ansi cleancode,codesize', $cmd);
        $this->assertStringContainsString('--exclude "vendor"', $cmd);
        $this->assertStringContainsString('--cache', $cmd);
        $this->assertStringContainsString('--cache-file=.phpmd.cache', $cmd);
        $this->assertStringContainsString('--cache-strategy=content', $cmd);
        $this->assertStringContainsString('--suffixes=php,inc', $cmd);
        $this->assertStringContainsString('--baseline-file=phpmd-baseline.xml', $cmd);
        $this->assertStringContainsString('--strict', $cmd);
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

        $toolConfiguration = new ToolConfiguration('phpmd', $configuration, new ToolRegistry());

        $phpmd = new PhpmdFake($toolConfiguration);

        $this->assertStringContainsString('src,tests', $phpmd->prepareCommand());
        $this->assertStringContainsString('--exclude "vendor,app"', $phpmd->prepareCommand());
    }
}
