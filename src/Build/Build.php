<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Build;

use Exception;

class Build
{
    const ALL_BUILDS = ['githooks-7.4.tar', 'githooks-8.1.tar'];

    private string $phpVersion;

    private string $buildPath;

    public function __construct()
    {
        $this->setPhpVersion();
        $this->setBuildPath();
    }

    /**
     * Set the php version (only Major and Minor) used in the build process.
     */
    private function setPhpVersion(): void
    {
        $this->phpVersion = phpversion();
    }

    /**
     * Directory relative to the project where the build is saved. Two tiers:
     * 1. Base Build: 'builds/'. For php >= 8.1.0.
     * 2. Php 7.4, 8.0: 'builds/php7.4/'
     * See [release flow](.github/workflows/release.yml)
     */
    private function setBuildPath(): void
    {
        $path = 'builds' . DIRECTORY_SEPARATOR;
        if (version_compare($this->phpVersion, '7.4', '<')) {
            throw new Exception('GitHooks only supports php 7.4 or greater.', 1);
        }
        if (version_compare($this->phpVersion, '8.1.0', '<')) {
            $this->buildPath =  $path . 'php7.4' . DIRECTORY_SEPARATOR;
        } else {
            $this->buildPath =  $path;
        }
    }

    public function getBuildPath(): string
    {
        return $this->buildPath;
    }

    public function getBinary(): string
    {
        return $this->buildPath . 'githooks';
    }

    public function getPhpVersion(): string
    {
        return $this->phpVersion;
    }

    /**
     * Get the tar name for the build.
     */
    public function getTarName(): string
    {
        $majorMinor = implode('.', array_slice(explode('.', $this->phpVersion), 0, 2));
        if (in_array($majorMinor, ['7.4', '8.0'], true)) {
            return 'githooks-7.4.tar';
        }
        if (version_compare($majorMinor, '8.1', '>=')) {
            return 'githooks-8.1.tar';
        }
        throw new Exception('GitHooks only supports php 7.4 or greater.', 1);
    }
}
