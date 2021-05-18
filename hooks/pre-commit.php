#!/bin/php
<?php

use GitHooks\Commands\Console\RegisterBindings;
use Illuminate\Container\Container;

$rootPath = getcwd();

require $rootPath . '/vendor/autoload.php';

$backFiles = shell_exec('git diff --cached --name-only --diff-filter=ACM | grep ".php$\\|^composer.json$\\|^composer.lock$"');
if (!empty($backFiles)) {
    $container = Container::getInstance();

    $registerBindings = new RegisterBindings();

    $registerBindings->__invoke();

    $githooks = $container->makeWith(GitHooks\GitHooks::class);

    try {
        $githooks();
    } catch (\Throwable $th) {
        exit(1);
    }
}
