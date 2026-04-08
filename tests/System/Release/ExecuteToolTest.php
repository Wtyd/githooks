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

        // PHPUnit needs a test file and config to run successfully (must be PSR-12 compliant)
        file_put_contents(
            self::TESTS_PATH . '/src/PassingTest.php',
            '<?php

namespace TestsDir\Src;

use PHPUnit\Framework\TestCase;

class PassingTest extends TestCase
{
    public function testItPasses()
    {
        $this->assertTrue(true);
    }
}
'
        );

        file_put_contents(
            self::TESTS_PATH . '/phpunit.xml',
            '<?xml version="1.0" encoding="UTF-8"?>
<phpunit>
    <testsuites>
        <testsuite name="default">
            <directory>src</directory>
        </testsuite>
    </testsuites>
</phpunit>'
        );

        $this->configurationFileBuilder
            ->changeToolOption('phpunit', ['configuration' => self::TESTS_PATH . '/phpunit.xml'])
            ->changeToolOption('phpunit', ['group' => []])
            ->changeToolOption('phpunit', ['exclude-group' => []])
            ->changeToolOption('phpunit', ['filter' => ''])
            ->changeToolOption('phpunit', ['log-junit' => '']);

        // Psalm needs a config file to run
        $psalmDir = self::TESTS_PATH . '/qa';
        if (!is_dir($psalmDir)) {
            mkdir($psalmDir, 0777, true);
        }
        file_put_contents(
            "$psalmDir/psalm.xml",
            '<?xml version="1.0"?>
<psalm errorLevel="8" resolveFromConfigFile="true">
    <projectFiles>
        <directory name="../src" />
    </projectFiles>
</psalm>'
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
            'githooks.php',
            $this->configurationFileBuilder->setTools(['phpcs', 'phpcbf', 'parallel-lint', 'phpmd', 'phpcpd', 'phpstan', 'phpunit', 'psalm'])
                ->setOptions($executionModeFile)
                ->buildPhp()
        );
        passthru("$this->githooks tool all $executionModeArgument", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertToolHasBeenExecutedSuccessfully('phpcs');
        $this->assertToolHasBeenExecutedSuccessfully('phpcbf');
        $this->assertToolHasBeenExecutedSuccessfully('parallel-lint');
        $this->assertToolHasBeenExecutedSuccessfully('phpmd');
        $this->assertToolHasBeenExecutedSuccessfully('phpcpd');
        $this->assertToolHasBeenExecutedSuccessfully('phpstan');
        $this->assertToolHasBeenExecutedSuccessfully('phpunit');
        $this->assertToolHasBeenExecutedSuccessfully('psalm');
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
            $this->configurationFileBuilder->setTools(['phpcs', 'phpcbf', 'parallel-lint', 'phpmd', 'phpcpd', 'phpstan', 'phpunit', 'psalm'])
                ->setOptions($executionModeFile)
                ->buildPhp()
        );

        file_put_contents(
            self::TESTS_PATH . '/src/FileWithErrors.php',
            $this->phpFileBuilder->setFileName('FileWithErrors')->buildWithErrors(['phpcs', 'parallel-lint', 'phpmd', 'phpcpd', 'phpstan'])
        );

        $fileWithoutErrorsPath = self::TESTS_PATH . '/src/File.php';
        shell_exec("git add -f $fileWithoutErrorsPath");

        passthru("$this->githooks tool all $executionModeArgument", $exitCode);

        shell_exec('git restore -- ' . self::TESTS_PATH . "/.gitignore");
        shell_exec("git reset -- $fileWithoutErrorsPath");

        $this->assertEquals(1, $exitCode);
        $this->assertToolHasBeenExecutedSuccessfully('phpcs');
        $this->assertToolHasBeenExecutedSuccessfully('phpcbf');
        $this->assertToolHasBeenExecutedSuccessfully('parallel-lint');
        $this->assertToolHasBeenExecutedSuccessfully('phpmd');
        $this->assertToolHasFailed('phpcpd'); // No acelerable tool
        $this->assertToolHasBeenExecutedSuccessfully('phpstan');
        $this->assertToolHasBeenExecutedSuccessfully('phpunit'); // No acelerable tool
        $this->assertToolHasBeenExecutedSuccessfully('psalm');
    }

    /** @test */
    function it_returns_exit_0_when_phpcbf_fixes_bugs_automatically()
    {
        file_put_contents(
            'githooks.php',
            $this->configurationFileBuilder->setTools(['phpcbf'])
                ->buildPhp()
        );

        file_put_contents(
            self::TESTS_PATH . '/src/FileWithErrors.php',
            $this->phpFileBuilder->setFileName('FileWithErrors')->buildWithErrors(['phpcs'])
        );

        passthru("$this->githooks tool all", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertToolHasBeenExecutedSuccessfully('phpcbf');
    }

    /**
     * Only tools that reliably produce detectable errors across all PHP versions.
     * phpmd: parse errors in PHP 8.x don't count as violations (exit 0).
     * psalm: @return type mismatch classified as "issue" not "error" in PHP 8.x (exit 0).
     * phpcpd: excluded because its phar calls xdebug_disable() which crashes without xdebug.
     */
    public function allToolsProvider()
    {
        return [
            'Code Sniffer Phpcs' => [
                'phpcs'
            ],
            'Php Stan' => [
                'phpstan'
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
            'githooks.php',
            $this->configurationFileBuilder->setTools([$tool])
                ->changeToolOption($tool, ['ignoreErrorsOnExit' => true])
                ->buildPhp()
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
            'githooks.php',
            $this->configurationFileBuilder->setTools(['phpcs', 'phpcbf', 'parallel-lint', 'phpmd', 'phpcpd', 'phpstan', 'phpunit', 'psalm'])
                ->buildPhp()
        );
        passthru("$this->githooks tool all --processes=2", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertToolHasBeenExecutedSuccessfully('phpcs');
        $this->assertToolHasBeenExecutedSuccessfully('phpcbf');
        $this->assertToolHasBeenExecutedSuccessfully('parallel-lint');
        $this->assertToolHasBeenExecutedSuccessfully('phpmd');
        $this->assertToolHasBeenExecutedSuccessfully('phpcpd');
        $this->assertToolHasBeenExecutedSuccessfully('phpstan');
        $this->assertToolHasBeenExecutedSuccessfully('phpunit');
        $this->assertToolHasBeenExecutedSuccessfully('psalm');
    }

    /** @test */
    function it_returns_exit_0_when_phpunit_passes()
    {
        file_put_contents(
            'githooks.php',
            $this->configurationFileBuilder->setTools(['phpunit'])
                ->buildPhp()
        );

        passthru("$this->githooks tool phpunit", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertToolHasBeenExecutedSuccessfully('phpunit');
    }

    /** @test */
    function it_returns_exit_0_when_phpunit_fails_with_ignoreErrorsOnExit()
    {
        // Add a failing test alongside the passing one from setUp
        file_put_contents(
            self::TESTS_PATH . '/src/FailingTest.php',
            '<?php
use PHPUnit\Framework\TestCase;
class FailingTest extends TestCase
{
    public function testItFails()
    {
        $this->assertTrue(false);
    }
}'
        );

        file_put_contents(
            'githooks.php',
            $this->configurationFileBuilder->setTools(['phpunit'])
                ->changeToolOption('phpunit', ['ignoreErrorsOnExit' => true])
                ->buildPhp()
        );

        passthru("$this->githooks tool phpunit", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertToolHasFailed('phpunit');
    }

    /** @test */
    function it_returns_exit_0_when_psalm_passes()
    {
        file_put_contents(
            'githooks.php',
            $this->configurationFileBuilder->setTools(['psalm'])
                ->buildPhp()
        );

        passthru("$this->githooks tool psalm", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertToolHasBeenExecutedSuccessfully('psalm');
    }

    /** @test */
    function it_returns_exit_0_when_script_tool_runs_successfully()
    {
        file_put_contents(
            'githooks.php',
            $this->configurationFileBuilder->setTools(['script'])
                ->changeToolOption('script', ['executablePath' => 'echo'])
                ->changeToolOption('script', ['otherArguments' => 'hello'])
                ->buildPhp()
        );

        passthru("$this->githooks tool all", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertToolHasBeenExecutedSuccessfully('echo');
    }

    /** @test */
    function it_stops_execution_when_failFast_tool_fails()
    {
        file_put_contents(
            'githooks.php',
            $this->configurationFileBuilder->setTools(['parallel-lint', 'phpstan'])
                ->changeToolOption('parallel-lint', ['failFast' => true])
                ->buildPhp()
        );

        file_put_contents(
            self::TESTS_PATH . '/src/FileWithErrors.php',
            $this->phpFileBuilder->setFileName('FileWithErrors')->buildWithErrors(['parallel-lint'])
        );

        passthru("$this->githooks tool all", $exitCode);

        $this->assertEquals(1, $exitCode);
        $this->assertToolHasFailed('parallel-lint');
        $this->assertStringContainsString('skipped by failFast', $this->getActualOutput());
    }

    /** @test */
    function it_shows_detailed_output_when_a_tool_crashes()
    {
        // Configure only phpstan tool
        file_put_contents(
            'githooks.php',
            $this->configurationFileBuilder->setTools(['phpcs', 'phpcbf', 'parallel-lint', 'phpmd', 'phpcpd', 'phpstan'])
                ->buildPhp()
        );

        // Create file with invalid PHP syntax that will make phpstan crash
        file_put_contents(
            self::TESTS_PATH . '/src/FileWithSyntaxError.php',
            '<?php class BrokenFile { function broken() { $var = ; } }' // Invalid syntax
        );

        // Capture command output
        passthru("$this->githooks tool phpstan", $exitCode);
        $output = $this->getActualOutput();

        $this->assertEquals(1, $exitCode);
        $this->assertToolHasFailed('phpstan');
        $this->assertStringContainsString("Syntax error, unexpected ';' on line 1 ", $output);
        $this->assertStringContainsString('FileWithSyntaxError.php', $output);
        $this->assertStringContainsString('Syntax error', $output);
    }

    /** @test */
    function it_runs_tools_with_custom_config_path()
    {
        // Create a default config with an invalid tool in root (this would fail)
        file_put_contents(
            'githooks.php',
            $this->configurationFileBuilder->setTools(['invalid-tool'])->buildPhp()
        );

        // Create valid config in custom folder
        $this->createDirStructure('custom');

        file_put_contents(
            self::TESTS_PATH . '/custom/githooks.php',
            $this->configurationFileBuilder
                ->setTools(['phpcs', 'parallel-lint'])
                ->buildPhp()
        );

        passthru("$this->githooks tool all --config=" . self::TESTS_PATH . "/custom/githooks.php", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertToolHasBeenExecutedSuccessfully('phpcs');
        $this->assertToolHasBeenExecutedSuccessfully('parallel-lint');
    }

    /** @test */
    function it_applies_per_tool_execution_mode_override()
    {
        // Create file with phpcs AND phpstan errors (NOT staged in git)
        file_put_contents(
            self::TESTS_PATH . '/src/FileWithErrors.php',
            $this->phpFileBuilder->setFileName('FileWithErrors')->buildWithErrors(['phpcs', 'phpstan'])
        );

        // Stage the clean file only
        $fileWithoutErrorsPath = self::TESTS_PATH . '/src/File.php';
        shell_exec("git add -f $fileWithoutErrorsPath");

        // Global: full, but phpcs overridden to fast
        // In fast mode, phpcs only checks staged files (the clean one) → should pass
        // phpstan runs in full mode → checks all files in paths (including FileWithErrors) → should fail
        file_put_contents(
            'githooks.php',
            $this->configurationFileBuilder
                ->setTools(['phpcs', 'phpstan'])
                ->setOptions(['execution' => 'full'])
                ->changeToolOption('phpcs', ['execution' => 'fast'])
                ->buildPhp()
        );

        passthru("$this->githooks tool all", $exitCode);

        // Cleanup git staging
        shell_exec('git restore -- ' . self::TESTS_PATH . "/.gitignore");
        shell_exec("git reset -- $fileWithoutErrorsPath");

        $this->assertEquals(1, $exitCode);
        $this->assertToolHasBeenExecutedSuccessfully('phpcs'); // fast: only staged clean file
        $this->assertToolHasFailed('phpstan'); // full: checks all files, finds errors
    }
}
