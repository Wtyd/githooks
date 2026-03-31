<?php

namespace Tests\Unit\Tools\Tool;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;
use Wtyd\GitHooks\Tools\Tool\ParallelLint;
use Tests\Doubles\ParallelLintFake;
use Wtyd\GitHooks\Registry\ToolRegistry;

class ParallelLintTest extends UnitTestCase
{
    /** @test */
    function parallelLint_is_a_supported_tool()
    {
        $this->assertTrue((new ToolRegistry())->isSupported('parallel-lint'));
    }

    /** @test */
    function set_all_arguments_of_parallelLint_from_configuration_file()
    {
        $configuration = [
            'executablePath' => 'path/tools/parallel-lint',
            'paths' => ['./'],
            'exclude' => ['vendor', 'qa'],
            'jobs' => 10,
            'otherArguments' => '--colors',
            'ignoreErrorsOnExit' => false,
            'failFast' => true,
        ];

        $toolConfiguration = new ToolConfiguration('parallel-lint', $configuration, new ToolRegistry());

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
            'otherArguments' => '--colors',
            'ignoreErrorsOnExit' => true,
        ];

        $toolConfiguration = new ToolConfiguration('parallel-lint', $configuration, new ToolRegistry());

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
            'ignoreErrorsOnExit' => true,
            'unexpected or supported argument' => 'my value'
        ];

        $toolConfiguration = new ToolConfiguration('parallel-lint', $configuration, new ToolRegistry());

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
            'jobs' => 8,
            'otherArguments' => '--colors',
            'ignoreErrorsOnExit' => true,
        ];

        $toolConfiguration = new ToolConfiguration('parallel-lint', $configuration, new ToolRegistry());

        $parallelLint = new ParallelLintFake($toolConfiguration);

        $this->assertStringEndsWith('src tests', $parallelLint->prepareCommand());
        $this->assertStringContainsString('--exclude vendor --exclude app', $parallelLint->prepareCommand());
        $this->assertStringContainsString('-j 8', $parallelLint->prepareCommand());
    }
}
