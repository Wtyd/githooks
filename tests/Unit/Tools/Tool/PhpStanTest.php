<?php

namespace Tests\Unit\Tools\Tool;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;
use Wtyd\GitHooks\Tools\Tool\Phpstan;
use Wtyd\GitHooks\Tools\Tool\PhpstanFake;

class PhpStanTest extends UnitTestCase
{
    /** @test */
    function phpstan_is_a_supported_tool()
    {
        $this->assertTrue(Phpstan::checkTool('phpstan'));
    }

    /** @test */
    function set_all_arguments_of_phpstan_from_configuration_file()
    {
        $configuration = [
            'executablePath' => 'path/tools/phpstan',
            'paths' => ['src'],
            'config' => 'phpstan.neon',
            'memory-limit' => '1G',
            'level' => 5,
            'otherArguments' => '--no-progress',
            'ignoreErrorsOnExit' => true,
        ];

        $toolConfiguration = new ToolConfiguration('phpstan', $configuration);

        $phpstan = new PhpstanFake($toolConfiguration);

        $this->assertEquals($configuration['executablePath'], $phpstan->getExecutablePath());

        $this->assertEquals($configuration, $phpstan->getArguments());

        $this->assertCount(count(PhpstanFake::ARGUMENTS), $phpstan->getArguments());
    }

    /** @test */
    function it_sets_phpstan_in_executablePath_when_is_empty()
    {
        $configuration = [
            'paths' => ['src'],
            'config' => 'path/tools/phpstan.neon',
            'memory-limit' => '1G',
            'level' => 1,
            'otherArguments' => '--strict',
            'ignoreErrorsOnExit' => true,
        ];

        $toolConfiguration = new ToolConfiguration('phpstan', $configuration);

        $phpstan = new PhpstanFake($toolConfiguration);

        $this->assertEquals('phpstan', $phpstan->getExecutablePath());
    }

    /** @test */
    function it_ignores_unexpected_arguments_from_phpstan_configuration()
    {
        $configuration = [
            'paths' => ['src'],
            'executablePath' => 'path/tools/phpstan',
            'config' => 'path/tools/phpstan.neon',
            'memory-limit' => '1G',
            'level' => 1,
            'otherArguments' => '--strict',
            'ignoreErrorsOnExit' => true,
            'unexpected or supported argument' => 'my value'
        ];

        $toolConfiguration = new ToolConfiguration('phpstan', $configuration);

        $phpstan = new PhpstanFake($toolConfiguration);

        $this->assertEquals($configuration['executablePath'], $phpstan->getExecutablePath());

        unset($configuration['unexpected or supported argument']);
        $this->assertEquals($configuration, $phpstan->getArguments());
    }

    /**
     * @test
     * Phpstan doesn't have exclude argument. The excludes must be setted in phpstan config file
     */
    function it_sets_phpstan_to_run_against_several_paths()
    {
        $configuration = [
            'executablePath' => 'path/tools/phpstan',
            'paths' => ['src', 'tests'],
        ];

        $toolConfiguration = new ToolConfiguration('phpstan', $configuration);

        $phpstan = new PhpstanFake($toolConfiguration);

        $this->assertStringEndsWith('src tests', $phpstan->prepareCommand());
    }
}
