<?php

namespace Tests\Integration;

use Tests\Utils\TestCase\ConsoleTestCase;
use Wtyd\GitHooks\ConfigurationFile\FileReader;
use Wtyd\GitHooks\Tools\Tool\{
    CodeSniffer\Phpcbf,
    CodeSniffer\Phpcs,
    ParallelLint,
    Phpcpd,
    Phpmd,
    Phpstan,
    SecurityChecker
};

class IgnoreErrorsOnExitFlagTest extends ConsoleTestCase
{
    public function allToolsProvider()
    {
        return [
            'Code Sniffer Phpcs' => [
                Phpcs::class,
                'phpcs'
            ],
            'Code Sniffer Phpcbf' => [
                Phpcbf::class,
                'phpcbf'
            ],
            'Php Stan' => [
                Phpstan::class,
                'phpstan'
            ],
            'Php Mess Detector' => [
                Phpmd::class,
                'phpmd'
            ],
            'Php Copy Paste Detector' => [
                Phpcpd::class,
                'phpcpd'
            ],
            'Parallel-Lint' => [
                ParallelLint::class,
                'parallel-lint'
            ],
            'Composer Check-security' => [
                SecurityChecker::class,
                'security-checker'
            ],
        ];
    }

    /**
     * @test
     * @dataProvider allToolsProvider
     */
    function it_returns_exit_code_0_when_the_tool_fails_and_has_ignoreErrorsOnExit_set_to_true(
        $toolClass,
        $toolName
    ) {
        $fileReader = $this->app->make(FileReader::class);
        $fileReader->mockConfigurationFile(
            $this->configurationFileBuilder
                ->changeToolOption($toolName, ['ignoreErrorsOnExit' => true])
                ->buildArray()
        );

        $this->bindFakeTools();
        $this->app->resolving($toolClass, function ($toolMock) {
            $toolMock->fakeExit(1, ['Some error was found']);
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
        $toolClass,
        $toolName
    ) {
        $fileReader = $this->app->make(FileReader::class);
        $fileReader->mockConfigurationFile(
            $this->configurationFileBuilder
                ->changeToolOption($toolName, ['ignoreErrorsOnExit' => false])
                ->buildArray()
        );

        $this->bindFakeTools();
        $this->app->resolving($toolClass, function ($toolMock) {
            $toolMock->fakeExit(1, ['Some error was found']);
        });

        $this->artisan("tool $toolName")
            ->assertExitCode(1)
            ->toolHasFailed($toolName);
    }

    /**
     * @test
     * @dataProvider allToolsProvider
     */
    function it_returns_exit_code_0_when_the_failing_tool_has_ignoreErrorsOnExit_set_to_true($toolClass, $toolName)
    {
        $fileReader = $this->app->make(FileReader::class);
        $fileReader->mockConfigurationFile(
            $this->configurationFileBuilder
                ->changeToolOption($toolName, ['ignoreErrorsOnExit' => true])
                ->buildArray()
        );

        $this->bindFakeTools();
        $this->app->resolving($toolClass, function ($toolMock) {
            $toolMock->fakeExit(1, ['Some error was found']);
        });

        $this->artisan("tool all")
            ->assertExitCode(0)
            ->toolHasFailed($toolName);
    }

    /**
     * @test
     * @dataProvider allToolsProvider
     */
    function it_returns_exit_code_1_when_the_failing_tool_has_ignoreErrorsOnExit_set_to_false($toolClass, $toolName)
    {
        $fileReader = $this->app->make(FileReader::class);
        $fileReader->mockConfigurationFile(
            $this->configurationFileBuilder
                ->changeToolOption($toolName, ['ignoreErrorsOnExit' => false])
                ->buildArray()
        );

        $this->bindFakeTools();
        $this->app->resolving($toolClass, function ($toolMock) {
            $toolMock->fakeExit(1, ['Some error was found']);
        });

        $this->artisan("tool all")
            ->assertExitCode(1)
            ->toolHasFailed($toolName);
    }
}
