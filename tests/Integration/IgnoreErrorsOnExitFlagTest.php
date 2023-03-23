<?php

namespace Tests\Integration;

use Tests\Utils\TestCase\ConsoleTestCase;
use Wtyd\GitHooks\ConfigurationFile\FileReader;
use Wtyd\GitHooks\Tools\Process\Execution\MultiProcessesExecutionFake;
use Wtyd\GitHooks\Tools\Process\Execution\ProcessExecutionFake;

class IgnoreErrorsOnExitFlagTest extends ConsoleTestCase
{
    public function allToolsProvider()
    {
        return [
            'Code Sniffer Phpcs' => [
                'phpcs'
            ],
            'Code Sniffer Phpcbf' => [
                'phpcbf'
            ],
            'Php Stan' => [
                'phpstan'
            ],
            'Php Mess Detector' => [

                'phpmd'
            ],
            'Php Copy Paste Detector' => [
                'phpcpd'
            ],
            'Parallel-Lint' => [
                'parallel-lint'
            ],
            'Composer Check-security' => [
                'security-checker'
            ],
        ];
    }

    /**
     * @test
     * @dataProvider allToolsProvider
     */
    function it_returns_exit_code_0_when_the_tool_fails_and_has_ignoreErrorsOnExit_set_to_true(
        $toolName
    ) {
        $fileReader = $this->app->make(FileReader::class);
        $fileReader->mockConfigurationFile(
            $this->configurationFileBuilder
                ->changeToolOption($toolName, ['ignoreErrorsOnExit' => true])
                ->buildArray()
        );

        $this->app->resolving(ProcessExecutionFake::class, function ($processExecutionFake) use ($toolName) {
            $processExecutionFake->setToolsThatMustFail([$toolName]);
        });


        $this->artisan("tool $toolName")
            ->assertExitCode(0)
            ->toolHasFailed($toolName);
    }

    /**
     * @test
     * @dataProvider allToolsProvider
     */
    function it_returns_exit_code_1_when_the_tool_fails_and_has_ignoreErrorsOnExit_set_to_false(
        $toolName
    ) {
        $fileReader = $this->app->make(FileReader::class);
        $fileReader->mockConfigurationFile(
            $this->configurationFileBuilder
                ->changeToolOption($toolName, ['ignoreErrorsOnExit' => false])
                ->buildArray()
        );

        $this->app->resolving(ProcessExecutionFake::class, function ($processExecutionFake) use ($toolName) {
            $processExecutionFake->setToolsThatMustFail([$toolName]);
        });

        $this->artisan("tool $toolName")
            ->assertExitCode(1)
            ->toolHasFailed($toolName);
    }

    /**
     * @test
     * @dataProvider allToolsProvider
     */
    function it_returns_exit_code_0_when_the_failing_tool_has_ignoreErrorsOnExit_set_to_true($toolName)
    {
        $fileReader = $this->app->make(FileReader::class);
        $fileReader->mockConfigurationFile(
            $this->configurationFileBuilder
                ->changeToolOption($toolName, ['ignoreErrorsOnExit' => true])
                ->buildArray()
        );

        $this->app->resolving(MultiProcessesExecutionFake::class, function ($processExecutionFake) use ($toolName) {
            $processExecutionFake->setToolsThatMustFail([$toolName]);
        });

        $this->artisan('tool all')
            ->assertExitCode(0)
            ->toolHasFailed($toolName);
    }

    /**
     * @test
     * @dataProvider allToolsProvider
     */
    function it_returns_exit_code_1_when_the_failing_tool_has_ignoreErrorsOnExit_set_to_false($toolName)
    {
        $fileReader = $this->app->make(FileReader::class);
        $fileReader->mockConfigurationFile(
            $this->configurationFileBuilder
                ->changeToolOption($toolName, ['ignoreErrorsOnExit' => false])
                ->buildArray()
        );

        $this->app->resolving(MultiProcessesExecutionFake::class, function ($processExecutionFake) use ($toolName) {
            $processExecutionFake->setToolsThatMustFail([$toolName]);
        });

        $this->artisan("tool all")
            ->assertExitCode(1)
            ->toolHasFailed($toolName);
    }
}
