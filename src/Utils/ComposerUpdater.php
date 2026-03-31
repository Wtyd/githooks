<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Utils;

use Exception;
use Wtyd\GitHooks\Build\Build;

class ComposerUpdater
{
    public static function phpOldVersions(): void
    {
        $printer = new Printer();
        if (version_compare(phpversion(), '8.1.0', '>=')) {
            $printer->info('For php 8.1 or higher is not needed');
            return;
        }

        $rootPath = getcwd();
        $build = new Build();
        $origin = str_replace('/', DIRECTORY_SEPARATOR, "$rootPath/vendor/wtyd/githooks/") . $build->getBinary();
        $destiny = str_replace('/', DIRECTORY_SEPARATOR, "$rootPath/vendor/bin/githooks");
        if (file_exists($destiny)) {
            unlink($destiny);
        }

        try {
            copy($origin, $destiny);
            chmod($destiny, 0755);
            $printer->info('GitHooks was correctly installed');
        } catch (\Throwable $th) {
            $printer->error('Something went wrong');
            throw $th;
        }
    }

    /**
     * Directory relative to the project where the build is saved. Two tiers:
     * 1. Base Build: 'builds/'. For php >= 8.1.0.
     * 2. Php 7.4 and 8.0: 'builds/php7.4/'
     */
    public static function pathToBuild(): string
    {
        if (version_compare(phpversion(), '7.4.0', '<')) {
            throw new Exception('GitHooks only supports php 7.4 or greater.', 1);
        }

        if (version_compare(phpversion(), '8.1.0', '<')) {
            return 'php7.4';
        }

        return '';
    }
}
/*
"scripts": {
    "post-update-cmd": [
        "Wtyd\\GitHooks\\Utils\\ComposerUpdater::phpOldVersions"
    ]
}
 */
