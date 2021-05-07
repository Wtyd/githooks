<?php

namespace Tests;

use GitHooks\Commands\Console\RegisterBindings;
use GitHooks\Exception\ExitException;
use GitHooks\Utils\GitFilesInterface;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Version as PhpunitVersion;
use Tests\System\Utils\GitFilesFake;

/**
 * Al llamar a setOutputCallback escondemos cualquier output por la consola que no venga de phpunit
 */
class SystemTestCase extends TestCase
{
    use FileSystemTrait;
    use MockConfigurationFileTrait;

    protected $path = __DIR__ . '/System/tmp';

    protected static $assertMatchesRegularExpression;

    protected function setUp(): void
    {
        $registerBindings = new RegisterBindings();
        $registerBindings();
        $container =  Container::getInstance();
        $container->bind(GitFilesInterface::class, GitFilesFake::class);
    }

    public static function setUpBeforeClass(): void
    {
        self::$assertMatchesRegularExpression = self::setassertMatchesRegularExpressionpForm();
    }

    /**
     * The assertRegExp method is deprecated as of phpunit version 9. Replaced by the assertMatchesRegularExpression method.
     * This method checks the phpunit version and returns the name of the good method.
     *
     * @return string
     */
    protected static function setassertMatchesRegularExpressionpForm(): string
    {
        if (version_compare(PhpunitVersion::id(), '9.0.0', '<')) {
            return 'assertRegExp';
        } else {
            return 'assertMatchesRegularExpression';
        }
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
        $assertMatchesRegularExpression = self::$assertMatchesRegularExpression;
        $this->$assertMatchesRegularExpression("%$tool(\.phar)? - OK\. Time: \d+\.\d{2}%", $this->getActualOutput(), "The tool $tool has not been executed successfully");
    }

    protected function assertToolHasFailed(string $tool): void
    {
        //phpcbf[.phar] - KO. Time: 0.18
        $assertMatchesRegularExpression = self::$assertMatchesRegularExpression;
        $this->$assertMatchesRegularExpression("%$tool(\.phar)? - KO\. Time: \d+\.\d{2}%", $this->getActualOutput(), "The tool $tool has not failed");
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
        $assertMatchesRegularExpression = self::$assertMatchesRegularExpression;
        $this->$assertMatchesRegularExpression('%Total run time = \d+\.\d{2} sec%', $this->getActualOutput());
        $this->assertStringContainsString($failMessage, $this->getActualOutput());
    }
}
