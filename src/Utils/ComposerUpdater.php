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
        $origin = str_replace('/', DIRECTORY_SEPARATOR, "$rootPath/vendor/wtyd/githooks/") . $build->getBuildPath() . 'githooks';
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
     * Directory relative to the project where the build is saved. Depending on the version of php there will be 3 options:
     * 1. Base Build: 'builds/'. Actually for php greater than 8.0.0.
     * 2. Php 7.3 and 7.4: 'builds/php73/'
     * 3. Php 7.1 and 7.2: 'builds/php71/'
     */
    public static function pathToBuild(): string
    {
        if (version_compare(phpversion(), '7.1.0', '<')) {
            throw new Exception('GitHooks only supports php 7.1 or greater.', 1);
        }
        if (version_compare(phpversion(), '7.3.0', '<')) {
            return 'php7.1';
        }

        if (version_compare(phpversion(), '8.1.0', '<')) {
            return 'php7.3';
        }

        return '';
    }

    /**
     * The phar for php versions 7.2 and 7.1 is different from 7.3 or higher. The default phar is the latter, so to automate the
     * change of phar when it is updated with a "composer update wtyd/githooks" we must invoke this method from the
     * 'post-update-cmd' event of the section 'scripts' of the composer.json.
     * @deprecated 2.3.0 Use 'ComposerUpdater::phpOldVersions' instead.
     */
    public static function php72orMinorUpdate(): void
    {
        self::phpOldVersions();
    }
}
/*
"scripts": {
    "post-update-cmd": [
        "Wtyd\\GitHooks\\Utils\\ComposerUpdater::phpOldVersions"
    ]
}
 */
