<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Build;

use Exception;

class Build
{
    const ALL_BUILDS = ['githooks-7.1.tar', 'githooks-7.3.tar', 'githooks-8.1.tar'];

    /** @var string */
    private $phpVersion;

    /** @var string */
    private $buildPath;

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
     * Directory relative to the project where the build is saved. Depending on the version of php there will be 3 options:
     * 1. Base Build: 'builds/'. Actually for php greater than 8.1.0.
     * 2. Php 7.3, 7.4 and 8.0: 'builds/php73/'
     * 3. Php 7.1 and 7.2: 'builds/php71/'
     * See [release flow](.github/workflows/release.yml)
     */
    private function setBuildPath(): void
    {
        $path = 'builds' . DIRECTORY_SEPARATOR;
        // TODO: Refactor this to use a switch statement like getTarName()
        if (version_compare($this->phpVersion, '7.1', '<')) {
            throw new Exception('GitHooks only supports php 7.1 or greater.', 1);
        }
        if (version_compare($this->phpVersion, '7.3.0', '<')) {
            $this->buildPath =  $path . 'php7.1' . DIRECTORY_SEPARATOR;
        } elseif (version_compare($this->phpVersion, '8.1.0', '<')) {
            $this->buildPath =  $path . 'php7.3' . DIRECTORY_SEPARATOR;
        } else {
            $this->buildPath =  $path;
        }
    }

    public function getBuildPath(): string
    {
        return $this->buildPath;
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
        $tarName = '';
        switch (implode('.', array_slice(explode('.', $this->phpVersion), 0, 2))) {
            case '7.1':
            case '7.2':
                $tarName = 'githooks-7.1.tar';
                break;
            case '7.3':
            case '7.4':
            case '8.0':
                $tarName = 'githooks-7.3.tar';
                break;
            case '8.1':
            case '8.2':
            case '8.3':
                $tarName = 'githooks-8.1.tar';
                break;
            default:
                throw new Exception('GitHooks only supports php 7.1 or greater.', 1);
        }
        return $tarName;
    }
}
