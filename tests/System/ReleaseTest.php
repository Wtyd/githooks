<?php

namespace Tests\System;

use Tests\ConsoleTestCase;
use Tests\Utils\PhpFileBuilder;
use Tests\FileSystemTrait;
use Tests\Utils\ConfigurationFileBuilder;

class ReleaseTest extends ConsoleTestCase
{
    protected $githooksExecutable = ConsoleTestCase::TESTS_PATH . '/githooks';

    public static function setUpBeforeClass(): void
    {
        FileSystemTrait::cleanTestsFilesystem();

        self::copyReleaseBinary();

        // At this point I can't mock the path for githooks.yml. How as the first directory where
        // it looks is in the root I put this file in root directory
        $configurationFileBuilder = new ConfigurationFileBuilder(ConsoleTestCase::TESTS_PATH);
        file_put_contents(
            'githooks.yml',
            $configurationFileBuilder->setOptions(['invalidOptionTest' => 1])->buildYalm()
        );

        mkdir(ConsoleTestCase::TESTS_PATH  . '/src');
        $fileBuilder = new PhpFileBuilder('File');
        file_put_contents(
            ConsoleTestCase::TESTS_PATH . '/src/File.php',
            $fileBuilder->build()
        );
    }

    public static function tearDownAfterClass(): void
    {
        unlink('githooks.yml');
        unlink(ConsoleTestCase::TESTS_PATH  . '/githooks');
    }

    protected function setUp(): void
    {
        // do nothing
        // $this->setOutputCallback(function () {
        // });
    }
    protected function tearDown(): void
    {
        // do nothing
    }

    /**
     * Copies de releases candidate to the tests directory. Only copies the version that works in the current php version
     *
     * @return boolean
     */
    protected static function copyReleaseBinary(): bool
    {
        $origin = version_compare(phpversion(), '7.2.0', '<=') ? 'builds/php7.1/githooks' : 'builds/githooks';
        return copy($origin, ConsoleTestCase::TESTS_PATH . '/githooks');
    }


    function it_creates_the_configuration_file()
    {
        mkdir('vendor/wtyd/githooks/qa/', 0777, true);
        $configurationFileBuilder = new ConfigurationFileBuilder(ConsoleTestCase::TESTS_PATH);
        file_put_contents(
            'vendor/wtyd/githooks/qa/githooks.dist.yml',
            $configurationFileBuilder->buildYalm()
        );

        passthru("$this->githooksExecutable conf:init", $exitCode);

        $this->assertStringContainsString('Configuration file githooks.yml has been created in root path', $this->getActualOutput());
        $this->assertEquals(0, $exitCode);
        $this->assertFileExists('githooks.yml');

        $this->deleteDirStructure('vendor/wtyd');
    }

    function it_sets_a_custom_githook()
    {
        $scriptFile = ConsoleTestCase::TESTS_PATH . '/src/File.php';
        passthru("$this->githooksExecutable hook pre-push $scriptFile", $exitCode);

        $this->assertStringContainsString('Hook pre-push created', $this->getActualOutput());
        $this->assertEquals(0, $exitCode);
        $this->assertFileEquals($scriptFile, '.git/hooks/pre-push');
    }

    public function executionModeProvider()
    {
        return [['full'], ['']];
    }

    /**
     * @test
     * @dataProvider executionModeProvider
     */
    function it_executes_all_tools($executionMode)
    {
        $configurationFileBuilder = new ConfigurationFileBuilder(ConsoleTestCase::TESTS_PATH);
        file_put_contents(
            'githooks.yml',
            $configurationFileBuilder->setTools(['phpcs', 'parallel-lint', 'phpmd', 'phpcpd', 'phpstan'])->buildYalm()
        );
        passthru("$this->githooksExecutable tool all $executionMode", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertToolHasBeenExecutedSuccessfully('phpcbf');
        $this->assertToolHasBeenExecutedSuccessfully('parallel-lint');
        $this->assertToolHasBeenExecutedSuccessfully('phpmd');
        $this->assertToolHasBeenExecutedSuccessfully('phpcpd');
        $this->assertToolHasBeenExecutedSuccessfully('phpstan');
    }

    /** @test */
    function it_executes_all_tools_with_fast_execution_mode()
    {
        $configurationFileBuilder = new ConfigurationFileBuilder(ConsoleTestCase::TESTS_PATH);
        file_put_contents(
            'githooks.yml',
            $configurationFileBuilder->setTools(['phpcs', 'parallel-lint', 'phpmd', 'phpcpd', 'phpstan'])->buildYalm()
        );

        $fileBuilder = new PhpFileBuilder('FileWithErrors');

        file_put_contents(
            ConsoleTestCase::TESTS_PATH . '/src/FileWithErrors.php',
            $fileBuilder->buildWithErrors(['phpcs', 'parallel-lint', 'phpmd', 'phpcpd', 'phpstan'])
        );

        unlink('.gitignore');
        $fileWithoutErrorsPath = ConsoleTestCase::TESTS_PATH . '/src/File.php';
        shell_exec("git add $fileWithoutErrorsPath");
        passthru("$this->githooksExecutable tool all fast", $exitCode);

        shell_exec("git checkout -- .gitignore");
        shell_exec("git reset -- $fileWithoutErrorsPath");

        $this->assertEquals(1, $exitCode);
        $this->assertToolHasBeenExecutedSuccessfully('phpcbf');
        $this->assertToolHasBeenExecutedSuccessfully('parallel-lint');
        $this->assertToolHasBeenExecutedSuccessfully('phpmd');
        $this->assertToolHasFailed('phpcpd'); // No acelerable tool
        $this->assertToolHasBeenExecutedSuccessfully('phpstan');
    }
    //TODO ejecutar phpcs y ver que devuelve 1 cuando se corrige el fichero de forma automática

    /**
     * Checks if the $tool has been executed Successfully by regular expression assert. This assert was renamed and is deprecated
     * sinse phpunit 9.
     *
     * @param string $tool
     * @return void
     */
    protected function assertToolHasBeenExecutedSuccessfully(string $tool): void
    {
        //phpcbf - OK. Time: 0.18
        $this->assertMatchesRegularExpression("%$tool - OK\. Time: \d+\.\d{2}%", $this->getActualOutput(), "The tool $tool has not been executed successfully");
    }

    protected function assertToolHasFailed(string $tool): void
    {
        //phpcbf - KO. Time: 0.18
        $this->assertMatchesRegularExpression("%$tool - KO\. Time: \d+\.\d{2}%", $this->getActualOutput(), "The tool $tool has not failed");
    }
}
