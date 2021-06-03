<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Utils;

class ComposerUpdater
{
    /**
     * The phar for php versions 7.2 and 7.1 is different from 7.3 or higher. The default phar is the latter, so to automate the
     * change of phar when it is updated with a "composer update wtyd/githooks" we must invoke this method from the
     * 'post-update-cmd' event of the section 'scripts' of the composer.json.
     *
     * @return void
     */
    public static function php72orMinorUpdate()
    {
        $rootPaht = getcwd();
        $origin = "$rootPaht/vendor/wtyd/githooks/builds/php7.1/githooks";
        $destiny = "$rootPaht/vendor/bin/githooks";
        if (file_exists($destiny)) {
            unlink($destiny);
        }

        $printer = new Printer();
        try {
            copy($origin, $destiny);
            $printer->info('GitHooks was correctly installed');
        } catch (\Throwable $th) {
            $printer->error('Something went wrong');
            throw $th;
        }
    }
}
/**
"scripts": {
    "post-update-cmd": [
      "Wtyd\\GitHooks\\Utils\\ComposerUpdater::php72orMinorUpdate"
    ]
  }
 */
