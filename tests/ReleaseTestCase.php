<?php

namespace Tests;

use Wtyd\GitHooks\Exception\ExitException;
use PHPUnit\Framework\TestCase;
use Tests\Utils\ConfigurationFileBuilder;
use Tests\Utils\PhpFileBuilder;

class ReleaseTestCase extends TestCase
{
    use FileSystemTrait;
    use RetroCompatibilityAssertsTrait;

    /**
     * Executable binary
     *
     * @var string
     */
    protected $githooks = ConsoleTestCase::TESTS_PATH . '/githooks';

    /**
     * @var ConfigurationFileBuilder
     */
    protected $configurationFileBuilder;

    /**
     * @var PhpFileBuilder
     */
    protected $phpFileBuilder;

    protected function setUp(): void
    {
        $this->deleteDirStructure();

        $this->hiddenConsoleOutput();

        $this->createDirStructure();

        self::copyReleaseBinary();

        $this->configurationFileBuilder = new ConfigurationFileBuilder($this->path);

        $this->phpFileBuilder = new PhpFileBuilder('File');
    }


    protected function tearDown(): void
    {
        $this->deleteDirStructure();
        if (file_exists('githooks.yml')) {
            unlink('githooks.yml');
        }
    }

    protected function hiddenConsoleOutput(): void
    {
        $this->setOutputCallback(function () {
        });
    }


    /**
     * Copies de releases candidate to the tests directory. Only copies the version that works in the current php version.
     * Permissions must be setted. Otherwise, the Github Action flow will fail.
     *
     * @return boolean
     */
    protected static function copyReleaseBinary(): bool
    {
        $origin = version_compare(phpversion(), '7.2.0', '<=') ? 'builds/php7.1/githooks' : 'builds/githooks';
        $destiny = ConsoleTestCase::TESTS_PATH . '/githooks';
        copy($origin, $destiny);
        return chmod($destiny, 0777);
    }

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

    protected function assertToolDidNotRun(string $tool): void
    {
        $this->assertStringNotContainsString($tool, $this->getActualOutput(), "The tool $tool has been run");
    }

    /**
     * Verifies that some tool of all launched has failed.
     * 1. ExitException must be throwed.
     * 2. The summation of run time of all tools.
     * 3. Fail message.
     *
     * @param \Throwable $exception All errors and exceptions are cached for no break phpunit's flow.
     * @param string $failMessage Message printed when GitHooks finds an error.
     * @return void
     */
    protected function assertSomeToolHasFailed(\Throwable $exception, string $failMessage): void
    {
        $this->assertInstanceOf(ExitException::class, $exception);
        $this->assertMatchesRegularExpression('%Total run time = \d+\.\d{2} sec%', $this->getActualOutput());
        $this->assertStringContainsString($failMessage, $this->getActualOutput());
    }
}
