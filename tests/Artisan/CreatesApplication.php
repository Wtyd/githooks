<?php

namespace Tests\Artisan;

use GitHooks\Commands\Console\Kernel as GitHooksKernel;
use GitHooks\Commands\Console\RegisterBindings;
use GitHooks\Commands\Console\RegisterCommands;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Events\Dispatcher as EventsDispatcher;
use Illuminate\Foundation\Console\Kernel as ConcreteKernel;
use Mockery;
use Tests\Artisan\Application;

/**
 * Raise the laravel testing boilerplate
 */
trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * @return Tests\Artisan\Application
     */
    public function createApplication()
    {
        $this->resetMockery();

        $app = new Application(
            $_ENV['APP_BASE_PATH'] ?? dirname(getcwd())
        );

        $registerBindings = new RegisterBindings();
        $registerBindings();

        $app->bind(PendingCommand::class, Tests\System\Utils\Console\PendingCommand::class);

        $app->singleton(Dispatcher::class, EventsDispatcher::class);
        $app->singleton(GitHooksKernel::class, function () use ($app) {
            return new GitHooksKernel($app, $app->make(Dispatcher::class), $app->make(RegisterCommands::class));
        });

        $app->singleton(ConcreteKernel::class, GitHooksKernel::class);

        $app->singleton(Kernel::class, ConcreteKernel::class);

        return $app;
    }

    /**
     * I don't know why mockery has 37 asserts. This method reset Mockery Container and all asserts.
     *
     * @return void
     */
    protected function resetMockery()
    {
        Mockery::resetContainer();
        // $container = Mockery::getContainer();
        // var_dump($container->mockery_getExpectationCount()); //see extra asserts
    }
}
