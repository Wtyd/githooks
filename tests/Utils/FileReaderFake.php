<?php

namespace Tests\Utils;

use Tests\Utils\TestCase\SystemTestCase;
use Wtyd\GitHooks\ConfigurationFile\FileReader;

class FileReaderFake extends FileReader
{
    protected $mockConfigurationFile = [];

    public function __construct(string $rootPath = null)
    {
        $this->rootPath = $rootPath ? $rootPath : SystemTestCase::TESTS_PATH;
    }

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

    public function mockConfigurationFile(array $configurationFile)
    {
        $this->mockConfigurationFile = $configurationFile;
    }
}
