<?php

namespace Tests\Utils;

use Wtyd\GitHooks\Configuration;
use Wtyd\GitHooks\Exception\ConfigurationFileNotFoundException;
use Tests\SystemTestCase;

class ConfigurationFake extends Configuration
{
    /**
     * It Changes the original path on searchs for githooks.yml
     *
     * @return string The path of configuration file
     */
    protected function findConfigurationFile(): string
    {
        $configFile = SystemTestCase::TESTS_PATH . '/githooks.yml';

        if (file_exists($configFile)) {
            return $configFile;
        } else {
            throw new ConfigurationFileNotFoundException();
        }

        return $configFile;
    }
}
