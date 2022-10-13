<?php

namespace Wtyd\GitHooks\App\Updater;

use Humbug\SelfUpdate\Strategy\GithubStrategy;
use LaravelZero\Framework\Components\Updater\Strategy\StrategyInterface;
use Phar;
use Wtyd\GitHooks\Utils\ComposerUpdater;

class CustomStrategy extends GithubStrategy implements StrategyInterface
{
    /**
     * Returns the Download Url.
     *
     * @param array $package
     *
     * @return string
     */
    protected function getDownloadUrl(array $package): string
    {
        $downloadUrl = parent::getDownloadUrl($package);

        $downloadUrl = str_replace('releases/download', 'raw', $downloadUrl);

        return $downloadUrl . '/builds/' . ComposerUpdater::pathToBuild() . basename(Phar::running());
    }
}
//https://github.com/Wtyd/githooks/raw/1.0.2-alpha//builds/githooks
