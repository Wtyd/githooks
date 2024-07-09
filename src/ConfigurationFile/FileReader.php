<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\ConfigurationFile;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Wtyd\GitHooks\ConfigurationFile\Exception\ConfigurationFileNotFoundException;
use Wtyd\GitHooks\ConfigurationFile\Exception\ParseConfigurationFileException;

class FileReader
{
    /** @var string */
    protected $rootPath;

    public function __construct()
    {
        $this->rootPath = getcwd() ? getcwd() : '';
    }

    /**
     * @return array File configuration githooks.yml in associative array format.
     *
     * @throws \Wtyd\GitHooks\ConfigurationFile\Exception\ParseConfigurationFileException
     * @throws \Wtyd\GitHooks\ConfigurationFile\Exception\ConfigurationFileNotFoundException
     */
    public function readFile(): array
    {
        $configurationFilePath = $this->findConfigurationFile();

        try {
            $configurationFile = Yaml::parseFile($configurationFilePath);
        } catch (ParseException $exception) {
            throw ParseConfigurationFileException::forMessage($exception->getMessage());
        }

        return $configurationFile;
    }

    /**
     * Searchs configuration file 'githooks.yml' on root path and qa/ directory
     *
     * @return string The path of configuration file
     *
     * @throws \Wtyd\GitHooks\ConfigurationFile\Exception\ConfigurationFileNotFoundException
     */
    public function findConfigurationFile(): string
    {
        if (file_exists("$this->rootPath/githooks.yml")) {
            $configFile = "$this->rootPath/githooks.yml";
        } elseif (file_exists("$this->rootPath/qa/githooks.yml")) {
            $configFile = "$this->rootPath/qa/githooks.yml";
        } else {
            throw new ConfigurationFileNotFoundException();
        }

        return $configFile;
    }
}
