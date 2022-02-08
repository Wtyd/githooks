<?php

namespace Wtyd\GitHooks\App\Providers;

use Illuminate\Support\ServiceProvider;
use Wtyd\GitHooks\Tools\Tool\SecurityCheckerFake;
use Tests\Utils\FileReaderFake;
use Wtyd\GitHooks\ConfigurationFile\FileReader;
use Wtyd\GitHooks\Container\RegisterBindings;
use Wtyd\GitHooks\Tools\Tool\SecurityChecker;

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

        $this->testsRegister();
    }

    /**
     * Register fake clases for testing.
     *
     * The APP_ENV const is setted in Tests\CreatesApplication
     *
     * @return void
     */
    protected function testsRegister()
    {
        if (defined('APP_ENV') && APP_ENV === 'testing') {
            $this->app->singleton(FileReader::class, FileReaderFake::class);
            $this->app->singleton(SecurityChecker::class, SecurityCheckerFake::class);
        }
    }
}
