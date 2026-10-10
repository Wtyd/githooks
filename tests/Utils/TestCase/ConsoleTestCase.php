<?php

namespace Tests\Utils\TestCase;

use Tests\Doubles\GitStagerFake;
use Tests\Utils\ConfigurationFileBuilder;
use Tests\Zero\{
    ZeroTestCase,
    PendingCommand
};
use Wtyd\GitHooks\Utils\GitStagerInterface;
use Wtyd\GitHooks\Output\ProgressOutputHandler;

abstract class ConsoleTestCase extends ZeroTestCase
{
    public $containsStringInOutput = [];

    public $notContainsStringInOutput = [];

    public $matchesRegularExpression = [];

    public $notMatchesRegularExpression = [];

    public $expectedTables = [];

    /**
     * @var ConfigurationFileBuilder
     */
    protected $configurationFileBuilder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurationFileBuilder = new ConfigurationFileBuilder('');
        $this->app->bind(GitStagerInterface::class, GitStagerFake::class);

        // Silence ProgressOutputHandler in tests (writes to php://temp instead of STDERR)
        $this->app->bind(ProgressOutputHandler::class, function () {
            return new ProgressOutputHandler(fopen('php://temp', 'w'));
        });
        // The fakes the commands depend on (ConfigurationParser root, HookInstaller,
        // HookStatusInspector, ToolDetector) are registered in
        // AppServiceProvider::testsRegister() during bootstrap. Do NOT re-register
        // them here — the command instances were constructed during bootstrap and
        // hold references to those singletons; a re-bind would be invisible to them.
    }

    /**
     * Guard against "dead" output expectations.
     *
     * The fluent PendingCommand API (and a property assigned BEFORE the artisan()
     * call) accumulates expectations that {@see \Tests\Zero\PendingCommand::verifyExpectations()}
     * checks and then flushes when the command runs. A property assigned AFTER
     * `$this->artisan(...)->assertExitCode()` is a no-op: the temporary PendingCommand
     * already ran and verified (with the property still empty) when its statement ended.
     * Such leftovers are silent false-greens. If any expectation array is non-empty at
     * tear-down it was never verified — fail loudly so the assertion is made live.
     */
    protected function tearDown(): void
    {
        $unverified = [
            'containsStringInOutput'          => $this->containsStringInOutput,
            'notContainsStringInOutput'       => $this->notContainsStringInOutput,
            'matchesRegularExpression'        => $this->matchesRegularExpression,
            'notMatchesRegularExpression'     => $this->notMatchesRegularExpression,
        ];

        parent::tearDown();

        foreach ($unverified as $name => $values) {
            $this->assertSame(
                [],
                $values,
                "Output expectation '\$this->$name' was set but never verified. Chain it on the "
                . "command instead (\$this->artisan(...)->$name(...)->assertExitCode(...)), or assign it "
                . "BEFORE the artisan() call. Assigning it after assertExitCode() is a no-op."
            );
        }
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
}
