<?php

namespace Tests\Utils;

use Tests\Utils\TestCase\SystemTestCase;
use Wtyd\GitHooks\ConfigurationFile\Exception\ConfigurationFileNotFoundException;
use Wtyd\GitHooks\ConfigurationFile\FileReader;

class FileReaderFake extends FileReader
{
    protected $mockConfigurationFile = [];

    /**
     * For system tests it will read file from testing filesystem.
     * For unit tests or tests with less granularity than system tests it will return $mockConfigurationFile
     *
     * @return array
     */
    public function readFile(): array
    {
        if (empty($this->mockConfigurationFile)) {
            return parent::readFile();
        } else {
            return $this->mockConfigurationFile;
        }
    }

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

    public function mockConfigurationFile(array $configurationFile)
    {
        $this->mockConfigurationFile = $configurationFile;
    }
}
