<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\ConfigurationFile;

use InvalidArgumentException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Wtyd\GitHooks\ConfigurationFile\Exception\ConfigurationFileNotFoundException;
use Wtyd\GitHooks\ConfigurationFile\Exception\ParseConfigurationFileException;

class FileReader
{
    /** @var string */
    protected $rootPath;

    /** @var string */
    protected $configurationFilePath = '';

    public function __construct()
    {
        $this->rootPath = getcwd() ? getcwd() : '';
    }

    /**
     * @return array File configuration in associative array format.
     *
     * @throws \Wtyd\GitHooks\ConfigurationFile\Exception\ParseConfigurationFileException
     * @throws \Wtyd\GitHooks\ConfigurationFile\Exception\ConfigurationFileNotFoundException
     * @throws \InvalidArgumentException
     */
    public function readFile(string $configFile = ''): array
    {
        $this->configurationFilePath = '';
        if (!empty($configFile)) {
            if ($configFile[0] === '/' || preg_match('/^[a-zA-Z]:[\\\\\/]/', $configFile)) {
                $this->configurationFilePath = $configFile;
            } else {
                $this->configurationFilePath = $this->rootPath . DIRECTORY_SEPARATOR . $configFile;
            }
        } else {
            $this->configurationFilePath = $this->findConfigurationFile();
        }

        try {
            $fileExtension = pathinfo($this->configurationFilePath, PATHINFO_EXTENSION);
            if ($fileExtension === 'yml' || $fileExtension === 'yaml') {
                $configurationFile = Yaml::parseFile($this->configurationFilePath);
            } elseif ($fileExtension === 'php') {
                $configurationFile = require $this->configurationFilePath;
                if (!is_array($configurationFile)) {
                    throw new ParseConfigurationFileException('PHP configuration file does not return an array.');
                }
            } else {
                throw new InvalidArgumentException('Unsupported file type.');
            }
        } catch (ParseException $exception) {
            throw ParseConfigurationFileException::forMessage($exception->getMessage());
        }

        return $configurationFile;
    }

    protected function getConfigurationFilePath(): string
    {
        return $this->configurationFilePath;
    }

    public function getRelativeConfigurationFilePath(): string
    {
        $prefix = $this->rootPath . DIRECTORY_SEPARATOR;
        if (strpos($this->configurationFilePath, $prefix) === 0) {
            return substr($this->configurationFilePath, strlen($prefix));
        }

        return $this->configurationFilePath;
    }

    /**
     * Searchs configuration file on root path and qa/ directory
     *
     * @return string The path of configuration file
     *
     * @throws \Wtyd\GitHooks\ConfigurationFile\Exception\ConfigurationFileNotFoundException
     */
    public function findConfigurationFile(): string
    {
        $possiblePaths = [
            "$this->rootPath/githooks.php",
            "$this->rootPath/qa/githooks.php",
            "$this->rootPath/githooks.yml",
            "$this->rootPath/qa/githooks.yml"
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                $configFile = $path;
                break;
            }
        }

        if (!isset($configFile)) {
            throw new ConfigurationFileNotFoundException();
        }

        return $configFile;
    }
}
