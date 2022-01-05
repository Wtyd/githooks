<?php

namespace Tests\Unit\Tools\Tool;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;
use Wtyd\GitHooks\Tools\Tool\ParallelLint;
use Wtyd\GitHooks\Tools\Tool\ParallelLintFake;

class ParallelLintTest extends TestCase
{
    /** @test */
    function parallelLint_is_a_supported_tool()
    {
        $this->assertTrue(ParallelLint::checkTool('parallel-lint'));
    }

    /** @test */
    function set_all_arguments_of_parallelLint_from_configuration_file()
    {
        $configuration = [
            'executablePath' => 'path/tools/parallel-lint',
            'paths' => ['./'],
            'exclude' => ['vendor', 'qa'],
            'otherArguments' => '--colors',
        ];

        $toolConfiguration = new ToolConfiguration('parallel-lint', $configuration);

        $parallelLint = new ParallelLintFake($toolConfiguration);

        $this->assertEquals($configuration['executablePath'], $parallelLint->getExecutablePath());

        $this->assertEquals($configuration, $parallelLint->getArguments());

        $this->assertCount(count(ParallelLintFake::ARGUMENTS), $parallelLint->getArguments());
    }

    /** @test */
    function it_sets_parallelLint_in_executablePath_when_is_empty()
    {
        $configuration = [
            'paths' => ['./'],
            'exclude' => ['vendor', 'qa'],
            'otherArguments' => '--colors'
        ];

        $toolConfiguration = new ToolConfiguration('parallel-lint', $configuration);

        $parallelLint = new ParallelLintFake($toolConfiguration);

        $this->assertEquals('parallel-lint', $parallelLint->getExecutablePath());
    }

    /** @test */
    function it_ignores_unexpected_arguments_from_parallelLint_configuration()
    {
        $configuration = [
            'executablePath' => 'path/tools/parallel-lint',
            'paths' => ['./'],
            'exclude' => ['vendor', 'qa'],
            'otherArguments' => '--colors',
            'unexpected or supported argument' => 'my value'
        ];

        $toolConfiguration = new ToolConfiguration('parallel-lint', $configuration);

        $parallelLint = new ParallelLintFake($toolConfiguration);

        $this->assertEquals($configuration['executablePath'], $parallelLint->getExecutablePath());

        unset($configuration['unexpected or supported argument']);
        $this->assertEquals($configuration, $parallelLint->getArguments());
    }

    /** @test */
    function it_sets_parallelLint_to_run_against_and_ignore_several_paths()
    {
        $configuration = [
            'executablePath' => 'path/tools/parallel-lint',
            'paths' => ['src', 'tests'],
            'exclude' => ['vendor', 'app'],
            'otherArguments' => '--colors',
        ];

        $toolConfiguration = new ToolConfiguration('parallel-lint', $configuration);

        $parallelLint = new ParallelLintFake($toolConfiguration);

        $this->assertStringEndsWith('src tests', $parallelLint->prepareCommand());
        $this->assertStringContainsString('--exclude vendor --exclude app', $parallelLint->prepareCommand());
    }
}
