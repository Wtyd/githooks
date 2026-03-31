<?php

namespace Wtyd\GitHooks\App\Providers;

use Illuminate\Support\ServiceProvider;
use Wtyd\GitHooks\Container\RegisterBindings;

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
    protected function testsRegister(): void
    {
        if (defined('APP_ENV') && APP_ENV === 'testing') {
            \Wtyd\GitHooks\Utils\Storage::$disk = 'testing';
        }
    }
}
