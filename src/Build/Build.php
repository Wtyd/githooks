<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Build;

use Exception;

class Build
{
    private $phpVersion;
    private $buildPath;

    public function __construct()
    {
        $this->phpVersion = phpversion();

        $this->setBuildPath();
    }

    /**
     * Directory relative to the project where the build is saved. Depending on the version of php there will be 3 options:
     * 1. Base Build: 'builds/'. Actually for php greater than 8.1.0.
     * 2. Php 7.3, 7.4 and 8.0: 'builds/php73/'
     * 3. Php 7.1 and 7.2: 'builds/php71/'
     * See [release flow](.github/workflows/release.yml) 
     * @return void
     */
    private function setBuildPath(): void
    {
        $path = 'builds' . DIRECTORY_SEPARATOR;
        if (version_compare($this->phpVersion, '7.1.0', '<')) {
            throw new Exception('GitHooks only supports php 7.1 or greater.', 1);
        }
        if (version_compare($this->phpVersion, '7.3.0', '<')) {
            $this->buildPath =  $path . DIRECTORY_SEPARATOR . 'php7.1' . DIRECTORY_SEPARATOR;
        } elseif (version_compare($this->phpVersion, '8.1.0', '<')) {
            $this->buildPath =  $path . DIRECTORY_SEPARATOR . 'php7.3' . DIRECTORY_SEPARATOR;
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

    public function getTarName(): string
    {
        return 'githooks-' . $this->phpVersion . '.tar';
    }
}
