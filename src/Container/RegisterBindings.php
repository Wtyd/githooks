<?php

namespace Wtyd\GitHooks\Container;

use Illuminate\Container\Container;
use Wtyd\GitHooks\Utils\GitFiles;
use Wtyd\GitHooks\Utils\GitFilesInterface;

class RegisterBindings
{
    /**
     * Makes efectively the bindings
     *
     * @return void
     */
    public function register(): void
    {
        $container = Container::getInstance();

        foreach ($this->binds() as $key => $value) {
            $container->bind($key, $value);
        }

        foreach ($this->binds() as $key => $value) {
            $container->singleton($key, $value);
        }
    }

    /**
     * Register with bind method
     *
     * @return array
     */
    protected function binds(): array
    {
        return [GitFilesInterface::class => GitFiles::class];
    }

    /**
     * Register singletons
     *
     * @return array
     */
    protected function singletons(): array
    {
        return [];
    }
}
