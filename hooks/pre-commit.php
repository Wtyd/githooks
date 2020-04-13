<?php

use Illuminate\Container\Container;

$rootPath = getcwd();

require $rootPath . '/vendor/autoload.php';

$backFiles = shell_exec('git diff --cached --name-only --diff-filter=ACM | grep ".php$\\|^composer.json$\\|^composer.lock$"');
if (! empty($backFiles)) {
    $configFile = $rootPath . '/qa/githooks.yml';

    $container = Container::getInstance();

    $githooks = $container->makeWith(GitHooks\GitHooks::class, ['configFile' => $configFile]);

    try {
        $githooks();
    } catch (\Throwable $th) {
        exit(1);
    }
}
