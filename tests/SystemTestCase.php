<?php

namespace Tests;

use GitHooks\Configuration;
use GitHooks\Exception\ExitException;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Version as PhpunitVersion;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\System\ExecutableFinderTest;
use Tests\System\Utils\ConfigurationFake;

/**
 * Al llamar a setOutputCallback escondemos cualquier output por la consola que no venga de phpunit
 */
class SystemTestCase extends TestCase
{
    protected $path = __DIR__ . '/System/tmp';

    /**
     * @var Container
     */
    protected $container;

    protected $assertMatchesRegularExpression;

    public function __construct()
    {
        parent::__construct();

        $this->assertMatchesRegularExpression = $this->setassertMatchesRegularExpressionpForm();
    }

    /**
     * The assertMatchesRegularExpressionp method is deprecated as of phpunit version 9. Replaced by the assertMatchesRegularExpression method.
     * This method checks the phpunit version and returns the name of the correct method.
     *
     * @return string
     */
    protected function setassertMatchesRegularExpressionpForm(): string
    {
        if (version_compare(PhpunitVersion::id(), '9.0.0', '<')) {
            return 'assertRegExp';
        } else {
            return 'assertMatchesRegularExpression';
        }
    }

    /**
     * For system tests I need to read the configuration file 'githooks.yml' but the SUT looks for it in the root or qa / directories.
     * In order to use a configuration file created expressly for each test, I mock the 'findConfigurationFile' method so that
     * return the root directory where I create the file structure for the tests ($this->path)
     *
     * For ExecutableFinderTest I can't use Mockery in two of three stages (only when I install the application with dev dependencies).
     * For this, I have created ConfigurationFake.
     *
     * @return void
     */
    protected function mockPathGitHooksConfigurationFile(): void
    {
        if ($this instanceof ExecutableFinderTest) {
            $this->container->bind(Configuration::class, ConfigurationFake::class);
        } else {
            $mockConfiguration = Mock::mock(Configuration::class)->shouldAllowMockingProtectedMethods()->makePartial();
            $mockConfiguration->shouldReceive('findConfigurationFile')->andReturn($this->path . '/githooks.yml');
            $this->container->instance(Configuration::class, $mockConfiguration);
        }
    }

    protected function hiddenConsoleOutput(): void
    {
        $this->setOutputCallback(function () {
        });
    }

    protected function deleteDir(): void
    {
        $dir = $this->path;
        $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator(
            $it,
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        rmdir($dir);
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function createDirStructure(): void
    {
        mkdir($this->path);
        mkdir($this->path . '/src');
        mkdir($this->path . '/vendor');
    }

    public function deleteDirStructure(): void
    {
        if (is_dir($this->path)) {
            $this->deleteDir();
        }
    }

    //assertMatchesRegularExpression
    //assertMatchesRegularExpressionp
    protected function assertToolHasBeenExecutedSuccessfully(string $tool): void
    {
        //phpcbf[.phar] - OK. Time: 0.18
        $assertMatchesRegularExpression = $this->assertMatchesRegularExpression;
        $this->$assertMatchesRegularExpression('%Total run time = \d+\.\d{2} sec%', $this->getActualOutput(), "The tool $tool has not been executed successfully");
    }

    protected function assertToolHasFailed(string $tool): void
    {
        //phpcbf[.phar] - KO. Time: 0.18
        $assertMatchesRegularExpression = $this->assertMatchesRegularExpression;
        $this->$assertMatchesRegularExpression('%Total run time = \d+\.\d{2} sec%', $this->getActualOutput(), "The tool $tool has not failed");
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
        $assertMatchesRegularExpression = $this->assertMatchesRegularExpression;
        $this->$assertMatchesRegularExpression('%Total run time = \d+\.\d{2} sec%', $this->getActualOutput());
        $this->assertStringContainsString('Your changes have not been committed. Please fix the errors and try again.', $this->getActualOutput());
    }
}
