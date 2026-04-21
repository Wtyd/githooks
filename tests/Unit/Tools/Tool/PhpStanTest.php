<?php

namespace Tests\Unit\Tools\Tool;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;
use Wtyd\GitHooks\Tools\Tool\Phpstan;
use Tests\Doubles\PhpstanFake;
use Wtyd\GitHooks\Registry\ToolRegistry;

class PhpStanTest extends UnitTestCase
{
    /** @test */
    function phpstan_is_a_supported_tool()
    {
        $this->assertTrue((new ToolRegistry())->isSupported('phpstan'));
    }

    /** @test */
    function set_all_arguments_of_phpstan_from_configuration_file()
    {
        $configuration = [
            'executablePath' => 'path/tools/phpstan',
            'paths' => ['src'],
            'config' => 'phpstan.neon',
            'memory-limit' => '1G',
            'error-format' => 'json',
            'no-progress' => true,
            'clear-result-cache' => true,
            'level' => 5,
            'otherArguments' => '--ansi',
            'ignoreErrorsOnExit' => true,
            'failFast' => false,
        ];

        $toolConfiguration = new ToolConfiguration('phpstan', $configuration, new ToolRegistry());

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
            'no-progress' => true,
            'otherArguments' => '--strict',
            'ignoreErrorsOnExit' => true,
        ];

        $toolConfiguration = new ToolConfiguration('phpstan', $configuration, new ToolRegistry());

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
            'error-format' => 'json',
            'no-progress' => true,
            'clear-result-cache' => true,
            'level' => 1,
            'otherArguments' => '--strict',
            'ignoreErrorsOnExit' => true,
            'unexpected or supported argument' => 'my value'
        ];

        $toolConfiguration = new ToolConfiguration('phpstan', $configuration, new ToolRegistry());

        $phpstan = new PhpstanFake($toolConfiguration);

        $this->assertEquals($configuration['executablePath'], $phpstan->getExecutablePath());

        unset($configuration['unexpected or supported argument']);
        $this->assertEquals($configuration, $phpstan->getArguments());
    }

    /** @test */
    function it_prepares_phpstan_command_with_all_arguments()
    {
        $configuration = [
            'executablePath' => 'vendor/bin/phpstan',
            'config' => 'phpstan.neon',
            'level' => 8,
            'memory-limit' => '1G',
            'error-format' => 'json',
            'no-progress' => true,
            'clear-result-cache' => true,
            'otherArguments' => '--ansi',
            'paths' => ['src', 'app'],
        ];

        $toolConfiguration = new ToolConfiguration('phpstan', $configuration, new ToolRegistry());
        $phpstan = new PhpstanFake($toolConfiguration);

        $cmd = $phpstan->prepareCommand();
        $this->assertStringStartsWith('vendor/bin/phpstan analyse', $cmd);
        // Space-sensitive asserts kill L102 Concat / ConcatOperandRemoval mutants that
        // would produce a glued or empty fragment for otherArguments.
        $this->assertStringContainsString(' -c phpstan.neon', $cmd);
        $this->assertStringContainsString(' -l 8', $cmd);
        $this->assertStringContainsString(' --memory-limit=1G', $cmd);
        $this->assertStringContainsString(' --error-format=json', $cmd);
        $this->assertStringContainsString(' --no-progress', $cmd);
        $this->assertStringContainsString(' --clear-result-cache', $cmd);
        $this->assertStringContainsString(' --ansi', $cmd);
        $this->assertStringNotContainsString('  ', $cmd);
        $this->assertStringEndsWith('src app', $cmd);
    }

    /**
     * @test
     * Mutant L102 Concat / ConcatOperandRemoval: the default branch renders
     * otherArguments with a leading space. Dropping the space or the value
     * would be invisible to a `--ansi` substring match; the leading-space
     * assertion kills both mutations.
     */
    function other_arguments_are_rendered_with_leading_space()
    {
        $toolConfiguration = new ToolConfiguration('phpstan', [
            'executablePath' => 'tools/phpstan',
            'paths'          => ['src'],
            'otherArguments' => '--ansi',
        ], new ToolRegistry());
        $phpstan = new PhpstanFake($toolConfiguration);

        $cmd = $phpstan->prepareCommand();

        $this->assertStringContainsString(' --ansi', $cmd);
        $this->assertStringNotContainsString('analyse--ansi', $cmd);
        $this->assertStringNotContainsString('  ', $cmd);
    }

    /**
     * @test
     * When otherArguments is absent the command must not emit stray whitespace.
     */
    function command_without_other_arguments_has_no_double_or_trailing_space()
    {
        $toolConfiguration = new ToolConfiguration('phpstan', [
            'executablePath' => 'tools/phpstan',
            'paths'          => ['src'],
        ], new ToolRegistry());
        $phpstan = new PhpstanFake($toolConfiguration);

        $cmd = $phpstan->prepareCommand();

        $this->assertStringNotContainsString('  ', $cmd);
        $this->assertSame($cmd, rtrim($cmd));
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

        $toolConfiguration = new ToolConfiguration('phpstan', $configuration, new ToolRegistry());

        $phpstan = new PhpstanFake($toolConfiguration);

        $this->assertStringEndsWith('src tests', $phpstan->prepareCommand());
    }
}
