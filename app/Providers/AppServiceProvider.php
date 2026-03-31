<?php

namespace Wtyd\GitHooks\App\Providers;

use Illuminate\Support\ServiceProvider;
use Wtyd\GitHooks\ConfigurationFile\FileReader;
use Wtyd\GitHooks\Container\RegisterBindings;
use Wtyd\GitHooks\Tools\Process\ProcessExecutionFactory\ProcessExecutionFactoryAbstract;
use Wtyd\GitHooks\Tools\Process\ProcessExecutionFactory\ProcessExecutionFactoryFake;
use Wtyd\GitHooks\Tools\Tool\SecurityChecker;
use Tests\Doubles\FileReaderFake;
use Tests\Doubles\SecurityCheckerFake;
use Wtyd\GitHooks\Utils\Storage;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $register = new RegisterBindings();
        $register->register();

        // For php 7.1 self-update
        // $this->app->bind(Humbug\SelfUpdate\Updater::class, App\Updater\Updater::class);

        // Bind my custom BuildCommand
        $this->app->bind(
            \LaravelZero\Framework\Commands\BuildCommand::class,
            \Wtyd\GitHooks\App\Commands\Zero\BuildCommand::class
        );
        $this->testsRegister();
    }

    /**
     * Register testing overrides.
     *
     * The APP_ENV const is setted in Tests\CreatesApplication
     *
     * @return void
     */
    /**
     * Register fake classes for testing.
     *
     * These MUST run during bootstrap (before Artisan instantiates commands)
     * so that commands receive fakes via constructor DI. ConsoleTestCase::setUp()
     * re-registers the same bindings but by that point command instances are cached.
     *
     * The APP_ENV const is set in Tests\CreatesApplication.
     */
    protected function testsRegister(): void
    {
        if (defined('APP_ENV') && APP_ENV === 'testing') {
            $this->app->singleton(FileReader::class, FileReaderFake::class);
            $this->app->singleton(SecurityChecker::class, SecurityCheckerFake::class);
            $this->app->singleton(ProcessExecutionFactoryAbstract::class, ProcessExecutionFactoryFake::class);
            Storage::$disk = 'testing';
        }
    }
}
