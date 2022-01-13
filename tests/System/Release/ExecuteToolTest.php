<?php

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * @group release
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
                ->buildYalm()
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
            'githooks.yml',
            $this->configurationFileBuilder->setTools(['phpcs', 'phpcbf', 'parallel-lint', 'phpmd', 'phpcpd', 'phpstan'])
                ->setOptions($executionModeFile)
                ->buildYalm()
        );

        file_put_contents(
            self::TESTS_PATH . '/src/FileWithErrors.php',
            $this->phpFileBuilder->setFileName('FileWithErrors')->buildWithErrors(['phpcs', 'parallel-lint', 'phpmd', 'phpcpd', 'phpstan'])
        );

        unlink('.gitignore');
        $fileWithoutErrorsPath = self::TESTS_PATH . '/src/File.php';
        shell_exec("git add $fileWithoutErrorsPath");
        passthru("$this->githooks tool all $executionModeArgument", $exitCode);

        shell_exec("git checkout -- .gitignore");
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
                ->buildYalm()
        );

        file_put_contents(
            self::TESTS_PATH . '/src/FileWithErrors.php',
            $this->phpFileBuilder->setFileName('FileWithErrors')->buildWithErrors(['phpcs'])
        );

        passthru("$this->githooks tool all", $exitCode);

        $this->assertEquals(1, $exitCode);
        $this->assertToolHasFailed('phpcbf');
    }
}
