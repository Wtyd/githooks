<?php

namespace Tests\Utils;

use Tests\ConsoleTestCase;
use Wtyd\GitHooks\ConfigurationFile\Exception\ConfigurationFileNotFoundException;
use Wtyd\GitHooks\ConfigurationFile\FileReader;

class FileReaderFake extends FileReader
{
    /**
     * It Changes the original path on searchs for githooks.yml
     *
     * @return string The path of configuration file
     */
    protected function findConfigurationFile(): string
    {
        $configFile = ConsoleTestCase::TESTS_PATH . '/githooks.yml';

        if (file_exists($configFile)) {
            return $configFile;
        } else {
            throw new ConfigurationFileNotFoundException();
        }

        return $configFile;
    }
}
