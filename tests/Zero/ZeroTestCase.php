<?php

declare(strict_types=1);

namespace Tests\Zero;

use Illuminate\Support\Facades\Facade;
use LaravelZero\Framework\Providers\CommandRecorder\CommandRecorderRepository;
use NunoMaduro\Collision\ArgumentFormatter;
use Tests\Utils\Traits\CreatesApplication;

abstract class ZeroTestCase extends IlluminateTestCase
{
    use CreatesApplication;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void
    {
        if (!$this->app) {
            $this->refreshApplication();
        }

        $this->setUpTraits();

        foreach ($this->afterApplicationCreatedCallbacks as $callback) {
            call_user_func($callback);
        }

        Facade::clearResolvedInstances();

        if (class_exists(\Illuminate\Database\Eloquent\Model::class)) {
            \Illuminate\Database\Eloquent\Model::setEventDispatcher($this->app['events']);
        }

        $this->setUpHasRun = true;
    }

    /**
     * Assert that a command was called using the given arguments.
     *
     * @param string $command
     * @param array $arguments
     */
    protected function assertCommandCalled(string $command, array $arguments = []): void
    {
        $argumentsAsString = (new ArgumentFormatter())->format($arguments);
        $recorder = app(CommandRecorderRepository::class);

        static::assertTrue(
            $recorder->exists($command, $arguments),
            'Failed asserting that \'' . $command . '\' was called with the given arguments: ' . $argumentsAsString
        );
    }

    /**
     * Assert that a command was not called using the given arguments.
     *
     * @param string $command
     * @param array $arguments
     */
    protected function assertCommandNotCalled(string $command, array $arguments = []): void
    {
        $argumentsAsString = (new ArgumentFormatter())->format($arguments);
        $recorder = app(CommandRecorderRepository::class);

        static::assertFalse(
            $recorder->exists($command, $arguments),
            'Failed asserting that \'' . $command . '\' was not called with the given arguments: ' . $argumentsAsString
        );
    }
}
