<?php

namespace App\Updater;

use Humbug\SelfUpdate\Strategy\GithubStrategy;
use LaravelZero\Framework\Components\Updater\Strategy\StrategyInterface;
// use LaravelZero\Framework\Components\Updater\Strategy\GitHubStrategy;

use Phar;

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
        $pathToBuild = '';
        if (version_compare(phpversion(), '7.2.0', '<')) {
            $pathToBuild = '/builds/php71/';
        } else {
            $pathToBuild = '/builds/';
        }
        $downloadUrl = parent::getDownloadUrl($package);

        $downloadUrl = str_replace('releases/download', 'raw', $downloadUrl);

        return $downloadUrl . $pathToBuild . basename(Phar::running());
    }
}
//https://github.com/Wtyd/githooks/raw/1.0.2-alpha//builds/githooks
