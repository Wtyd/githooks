#!/bin/php
<?php

use Illuminate\Container\Container;
use Wtyd\GitHooks\Container\RegisterBindings;

$rootPath = getcwd();

require $rootPath . '/vendor/autoload.php';

$backFiles = shell_exec('git diff --cached --name-only --diff-filter=ACM | grep ".php$\\|^composer.json$\\|^composer.lock$"');
if (!empty($backFiles)) {
    $container = Container::getInstance();

    $registerBindings = new RegisterBindings();

    $registerBindings->register();

    $githooks = $container->makeWith(Wtyd\GitHooks\GitHooks::class);

    try {
        $githooks();
    } catch (\Throwable $th) {
        exit(1);
    }
}
