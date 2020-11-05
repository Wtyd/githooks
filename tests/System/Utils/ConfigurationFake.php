<?php

namespace Tests\System\Utils;

use GitHooks\Configuration;
use GitHooks\Exception\ConfigurationFileNotFoundException;

class ConfigurationFake extends Configuration
{
    /**
     * It Changes the original path on searchs for githooks.yml
     *
     * @return string The path of configuration file
     */
    protected function findConfigurationFile(): string
    {
        $root = getcwd();
        $path = 'tests/System/tmp/githooks.yml';

        if (file_exists("$root/$path")) {
            $configFile = "$root/$path";
        } else {
            throw new ConfigurationFileNotFoundException();
        }

        return $configFile;
    }
}
