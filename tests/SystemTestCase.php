<?php

namespace Tests;

use Wtyd\GitHooks\Commands\Console\RegisterBindings;
use Wtyd\GitHooks\Configuration;
use Wtyd\GitHooks\Exception\ExitException;
use Wtyd\GitHooks\Tools\CheckSecurity;
use Wtyd\GitHooks\Utils\GitFilesInterface;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Tests\Utils\CheckSecurityFake;
use Tests\Utils\ConfigurationFake;
use Tests\Utils\ConfigurationFileBuilder;
use Tests\Utils\GitFilesFake;

/**
 * Al llamar a setOutputCallback escondemos cualquier output por la consola que no venga de phpunit
 */
class SystemTestCase extends TestCase
{
    use FileSystemTrait;
    use RetroCompatibilityAssertsTrait;

    public const TESTS_PATH = __DIR__ . '/../testsDir';

    /**
     * @var Container
     */
    protected $container;

    /**
     * @var ConfigurationFileBuilder
     */
    protected $configurationFileBuilder;

    protected function setUp(): void
    {
        $this->deleteDirStructure();

        $this->hiddenConsoleOutput();

        $this->createDirStructure();

        $this->registerBindings();

        $this->configurationFileBuilder = new ConfigurationFileBuilder($this->path);
    }

    protected function registerBindings()
    {
        $this->container =  Container::getInstance();
        $this->container->bind(GitFilesInterface::class, GitFilesFake::class);
        $this->container->bind(Configuration::class, ConfigurationFake::class);
        $this->container->bind(CheckSecurity::class, CheckSecurityFake::class);
    }

    protected function tearDown(): void
    {
        $this->deleteDirStructure();
    }

    protected function hiddenConsoleOutput(): void
    {
        $this->setOutputCallback(function () {
        });
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
        //phpcbf[.phar] - OK. Time: 0.18
        $this->assertMatchesRegularExpression("%$tool(\.phar)? - OK\. Time: \d+\.\d{2}%", $this->getActualOutput(), "The tool $tool has not been executed successfully");
    }

    protected function assertToolHasFailed(string $tool): void
    {
        //phpcbf[.phar] - KO. Time: 0.18
        $this->assertMatchesRegularExpression("%$tool(\.phar)? - KO\. Time: \d+\.\d{2}%", $this->getActualOutput(), "The tool $tool has not failed");
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
