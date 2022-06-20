<?php

namespace Tests\Utils\TestCase;

use Tests\Utils\ConfigurationFileBuilder;
use Tests\Utils\Traits\RetroCompatibilityAssertsTrait;
use Tests\Zero\{
    ZeroTestCase,
    PendingCommand
};
use Wtyd\GitHooks\Tools\Execution\ProcessExecutionFactoryAbstract;
use Wtyd\GitHooks\Tools\Execution\ProcessExecutionFactoryFake;
use Wtyd\GitHooks\Tools\Tool\{
    CodeSniffer\Phpcbf,
    CodeSniffer\PhpcbfFake,
    CodeSniffer\Phpcs,
    CodeSniffer\PhpcsFake,
    ParallelLint,
    ParallelLintFake,
    Phpcpd,
    PhpcpdFake,
    Phpmd,
    PhpmdFake,
    Phpstan,
    PhpstanFake,
    SecurityChecker,
    SecurityCheckerFake
};

abstract class ConsoleTestCase extends ZeroTestCase
{
    use RetroCompatibilityAssertsTrait;

    public $containsStringInOutput = [];

    public $notContainsStringInOutput = [];

    public $matchesRegularExpression = [];

    public $notMatchesRegularExpression = [];

    public $toolHasBeenExecutedSuccessfully = [];

    public $toolHasFailed = [];

    public $toolDidNotRun = [];

    public $expectedTables = [];

    /**
     * @var ConfigurationFileBuilder
     */
    protected $configurationFileBuilder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurationFileBuilder = new ConfigurationFileBuilder('');
        $this->app->singleton(ProcessExecutionFactoryAbstract::class, ProcessExecutionFactoryFake::class);
    }

    /**
     * Call artisan command and return code.
     *
     * @param  string  $command
     * @param  array  $parameters
     * @return Tests\Artisan\PendingCommand|int
     */
    public function artisan($command, $parameters = [])
    {
        if (!$this->mockConsoleOutput) {
            return $this->app[Kernel::class]->call($command, $parameters);
        }

        return new PendingCommand($this, $this->app, $command, $parameters);
    }

    /**
     * Binds to the container the fake version of each tool.
     * Makes possible invoke to the resolving method and mock the exit of the tools (exit and exit code).
     *
     * @return void
     */
    protected function bindFakeTools(): void
    {
        $this->app->bind(ParallelLint::class, ParallelLintFake::class);
        $this->app->bind(Phpcpd::class, PhpcpdFake::class);
        $this->app->bind(Phpmd::class, PhpmdFake::class);
        $this->app->bind(Phpstan::class, PhpstanFake::class);
        $this->app->bind(SecurityChecker::class, SecurityCheckerFake::class);
        $this->app->bind(Phpstan::class, PhpstanFake::class);
        $this->app->bind(Phpcs::class, PhpcsFake::class);
        $this->app->bind(Phpcbf::class, PhpcbfFake::class);
    }
}
