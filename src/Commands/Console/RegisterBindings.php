<?php

namespace GitHooks\Commands\Console;

use GitHooks\Utils\GitFiles;
use GitHooks\Utils\GitFilesInterface;
use Illuminate\Container\Container;

class RegisterBindings
{
    public function __invoke(): void
    {
        $container =  Container::getInstance();
        $container->bind(GitFilesInterface::class, GitFiles::class);
    }
}
