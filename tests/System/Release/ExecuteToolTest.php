<?php

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * @group release
 * No way to run the tool Security-Checker for your flackness. That is, there is no way to make it pass or KO
 * in a controlled way.
 */
class ExecuteToolTest extends ReleaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        file_put_contents(
            self::TESTS_PATH . '/src/File.php',
            $this->phpFileBuilder->build()
        );
    }

    public function fullExecutionModeProvider()
    {
        return [
            'Without override execution mode in file' => [
                'Execution mode argument' => '',
                'Execution mode in file' => ['execution' => 'full']
            ],
            'Overriding execution mode in file' => [
                'Execution mode argument' => 'full',
                'Execution mode in file' => ['execution' => 'fast']
            ]
        ];
    }

    /**
     * @test
     * @dataProvider fullExecutionModeProvider
     */
    function it_returns_exit_0_when_executes_all_tools_and_all_pass($executionModeArgument, $executionModeFile)
    {
        file_put_contents(
            'githooks.yml',
            $this->configurationFileBuilder->setTools(['phpcs', 'phpcbf', 'parallel-lint', 'phpmd', 'phpcpd', 'phpstan'])
                ->setOptions($executionModeFile)
                ->buildYaml()
        );
        passthru("$this->githooks tool all $executionModeArgument", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertToolHasBeenExecutedSuccessfully('phpcs');
        $this->assertToolHasBeenExecutedSuccessfully('phpcbf');
        $this->assertToolHasBeenExecutedSuccessfully('parallel-lint');
        $this->assertToolHasBeenExecutedSuccessfully('phpmd');
        $this->assertToolHasBeenExecutedSuccessfully('phpcpd');
        $this->assertToolHasBeenExecutedSuccessfully('phpstan');
    }

    public function fastExecutionModeProvider()
    {
        return [
            'Without override execution mode in file' => [
                'Execution mode argument' => '',
                'Execution mode in file' => ['execution' => 'fast']
            ],
            'Overriding execution mode in file' => [
                'Execution mode argument' => 'fast',
                'Execution mode in file' => ['execution' => 'full']
            ]
        ];
    }

    /**
     * @test
     * @dataProvider fastExecutionModeProvider
     */
    function it_executes_all_tools_with_fast_execution_mode($executionModeArgument, $executionModeFile)
    {
        file_put_contents(
            'githooks.php',
            $this->configurationFileBuilder->setTools(['phpcs', 'phpcbf', 'parallel-lint', 'phpmd', 'phpcpd', 'phpstan'])
                ->setOptions($executionModeFile)
                ->buildPhp()
        );

        file_put_contents(
            self::TESTS_PATH . '/src/FileWithErrors.php',
            $this->phpFileBuilder->setFileName('FileWithErrors')->buildWithErrors(['phpcs', 'parallel-lint', 'phpmd', 'phpcpd', 'phpstan'])
        );

        // unlink('.gitignore');
        $fileWithoutErrorsPath = self::TESTS_PATH . '/src/File.php';
        shell_exec("git add $fileWithoutErrorsPath");

        passthru("$this->githooks tool all $executionModeArgument", $exitCode);

        shell_exec('git checkout -- ' . self::TESTS_PATH . "/.gitignore");
        shell_exec("git reset -- $fileWithoutErrorsPath");

        $this->assertEquals(1, $exitCode);
        $this->assertToolHasBeenExecutedSuccessfully('phpcs');
        $this->assertToolHasBeenExecutedSuccessfully('phpcbf');
        $this->assertToolHasBeenExecutedSuccessfully('parallel-lint');
        $this->assertToolHasBeenExecutedSuccessfully('phpmd');
        $this->assertToolHasFailed('phpcpd'); // No acelerable tool
        $this->assertToolHasBeenExecutedSuccessfully('phpstan');
    }

    /** @test */
    function it_returns_1_when_phpcs_finds_bugs_and_fixes_them_automatically()
    {
        file_put_contents(
            'githooks.yml',
            $this->configurationFileBuilder->setTools(['phpcbf'])
                ->buildYaml()
        );

        file_put_contents(
            self::TESTS_PATH . '/src/FileWithErrors.php',
            $this->phpFileBuilder->setFileName('FileWithErrors')->buildWithErrors(['phpcs'])
        );

        passthru("$this->githooks tool all", $exitCode);

        $this->assertEquals(1, $exitCode);
        $this->assertToolHasFailed('phpcbf');
    }

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
        ];
    }

    /**
     * @test
     * @dataProvider allToolsProvider
     */
    function it_returns_exit_code_0_when_the_tool_fails_and_has_ignoreErrorsOnExit_set_to_true($tool)
    {
        file_put_contents(
            'githooks.yml',
            $this->configurationFileBuilder->setTools([$tool])
                ->changeToolOption($tool, ['ignoreErrorsOnExit' => true])
                ->buildYaml()
        );

        file_put_contents(
            self::TESTS_PATH . '/src/FileWithErrors.php',
            $this->phpFileBuilder->setFileName('FileWithErrors')->buildWithErrors([$tool])
        );

        passthru("$this->githooks tool $tool", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertToolHasFailed($tool);
    }

    /** @test */
    function it_runs_all_tools_in_multipe_processes()
    {
        file_put_contents(
            'githooks.yml',
            $this->configurationFileBuilder->setTools(['phpcs', 'phpcbf', 'parallel-lint', 'phpmd', 'phpcpd', 'phpstan'])
                ->buildYaml()
        );
        passthru("$this->githooks tool all --processes=2", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertToolHasBeenExecutedSuccessfully('phpcs');
        $this->assertToolHasBeenExecutedSuccessfully('phpcbf');
        $this->assertToolHasBeenExecutedSuccessfully('parallel-lint');
        $this->assertToolHasBeenExecutedSuccessfully('phpmd');
        $this->assertToolHasBeenExecutedSuccessfully('phpcpd');
        $this->assertToolHasBeenExecutedSuccessfully('phpstan');
    }
}
