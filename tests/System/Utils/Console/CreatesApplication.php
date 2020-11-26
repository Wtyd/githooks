<?php

namespace Tests\System\Utils\Console;

use GitHooks\Commands\Console\Kernel as GitHooksKernel;
use Illuminate\Contracts\Console\Kernel;
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

        $app->bind(PendingCommand::class, Tests\System\Utils\Console\PendingCommand::class);

        $app->singleton(GitHooksKernel::class, GitHooksKernel::class);

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
        // var_dump($container->mockery_getExpectationCount()); //se extra asserts
    }
}
