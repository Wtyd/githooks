<?php

namespace Tests;

use GitHooks\Exception\ExitException;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Version as PhpunitVersion;

/**
 * Al llamar a setOutputCallback escondemos cualquier output por la consola que no venga de phpunit
 */
class SystemTestCase extends TestCase
{
    use FileSystemTrait;
    use MockConfigurationFileTrait;

    protected $path = __DIR__ . '/System/tmp';

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

    protected function hiddenConsoleOutput(): void
    {
        $this->setOutputCallback(function () {
        });
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
