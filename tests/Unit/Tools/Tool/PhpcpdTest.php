<?php

namespace Tests\Unit\Tools\Tool;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;
use Wtyd\GitHooks\Tools\Tool\Phpcpd;
use Wtyd\GitHooks\Tools\Tool\PhpcpdFake;

class PhpcpdTest extends UnitTestCase
{
    /** @test */
    function phpcpd_is_a_supported_tool()
    {
        $this->assertTrue(Phpcpd::checkTool('phpcpd'));
    }

    /** @test */
    function set_all_arguments_of_phpcpd_from_configuration_file()
    {
        $configuration = [
            'executablePath' => 'path/tools/phpcpd',
            'paths' => ['src'],
            'exclude' => ['vendor'],
            'min-lines' => 5,
            'min-tokens' => 70,
            'otherArguments' => '--fuzzy',
            'ignoreErrorsOnExit' => true,
        ];

        $toolConfiguration = new ToolConfiguration('phpcpd', $configuration);

        $phpcpd = new PhpcpdFake($toolConfiguration);

        $this->assertEquals($configuration['executablePath'], $phpcpd->getExecutablePath());

        $this->assertEquals($configuration, $phpcpd->getArguments());

        $this->assertCount(count(PhpcpdFake::ARGUMENTS), $phpcpd->getArguments());
    }

    /** @test */
    function it_sets_phpcpd_in_executablePath_when_is_empty()
    {
        $configuration = [
            'paths' => ['src'],
            'exclude' => ['vendor'],
            'min-lines' => 5,
            'otherArguments' => '--fuzzy',
            'ignoreErrorsOnExit' => true,
        ];

        $toolConfiguration = new ToolConfiguration('phpcpd', $configuration);

        $phpcpd = new PhpcpdFake($toolConfiguration);

        $this->assertEquals('phpcpd', $phpcpd->getExecutablePath());
    }

    /** @test */
    function it_ignores_unexpected_arguments_from_phpcpd_configuration()
    {
        $configuration = [
            'executablePath' => 'path/tools/phpcpd',
            'paths' => ['src'],
            'exclude' => ['vendor'],
            'min-lines' => 5,
            'otherArguments' => '--fuzzy',
            'ignoreErrorsOnExit' => true,
            'unexpected or supported argument' => 'my value'
        ];

        $toolConfiguration = new ToolConfiguration('phpcpd', $configuration);

        $phpcpd = new PhpcpdFake($toolConfiguration);

        $this->assertEquals($configuration['executablePath'], $phpcpd->getExecutablePath());

        unset($configuration['unexpected or supported argument']);
        $this->assertEquals($configuration, $phpcpd->getArguments());
    }

    /** @test */
    function it_prepares_phpcpd_command_with_min_lines_and_min_tokens()
    {
        $configuration = [
            'executablePath' => 'path/tools/phpcpd',
            'paths' => ['src'],
            'exclude' => ['vendor'],
            'min-lines' => 5,
            'min-tokens' => 70,
        ];

        $toolConfiguration = new ToolConfiguration('phpcpd', $configuration);
        $phpcpd = new PhpcpdFake($toolConfiguration);

        $cmd = $phpcpd->prepareCommand();
        $this->assertStringContainsString('--min-lines=5', $cmd);
        $this->assertStringContainsString('--min-tokens=70', $cmd);
        $this->assertStringEndsWith('src', $cmd);
    }

    /** @test */
    function it_sets_phpcpd_to_run_against_and_ignore_several_paths()
    {
        $configuration = [
            'executablePath' => 'path/tools/phpcpd',
            'paths' =>  ['src', 'tests'],
            'exclude' => ['vendor', 'app'],
        ];

        $toolConfiguration = new ToolConfiguration('phpcpd', $configuration);

        $phpcpd = new PhpcpdFake($toolConfiguration);

        $this->assertStringEndsWith('src tests', $phpcpd->prepareCommand());
        $this->assertStringContainsString('--exclude vendor --exclude app', $phpcpd->prepareCommand());
    }
}
