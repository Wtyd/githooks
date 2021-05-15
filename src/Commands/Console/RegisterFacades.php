<?php

namespace GitHooks\Commands\Console;

use Illuminate\Foundation\Bootstrap\RegisterFacades as BootstrapRegisterFacades;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\Facades\Facade;
use Illuminate\Contracts\Foundation\Application;

class RegisterFacades extends BootstrapRegisterFacades
{
    /**
     * Bootstrap the given application.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return void
     */
    public function bootstrap(Application $app)
    {
        Facade::clearResolvedInstances();

        Facade::setFacadeApplication($app);

        AliasLoader::getInstance(
            [
                'App' => Illuminate\Support\Facades\App::class,
                'Arr' => Illuminate\Support\Arr::class,
                'Artisan' => Illuminate\Support\Facades\Artisan::class,
            ]
        )->register();
    }
}
