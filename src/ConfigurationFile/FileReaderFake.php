<?php

namespace Wtyd\GitHooks\ConfigurationFile;

use Tests\Utils\TestCase\SystemTestCase;
use Wtyd\GitHooks\ConfigurationFile\FileReader;

class FileReaderFake extends FileReader
{
    /** @var array */
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

    /**
     * @param array $configurationFile Configuration file in associative array format for testing
     */
    public function mockConfigurationFile(array $configurationFile): void
    {
        $this->mockConfigurationFile = $configurationFile;
    }
}
