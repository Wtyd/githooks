<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\ConfigurationFile;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Wtyd\GitHooks\ConfigurationFile\Exception\ConfigurationFileNotFoundException;
use Wtyd\GitHooks\ConfigurationFile\Exception\ParseConfigurationFileException;

class FileReader
{
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
    protected function findConfigurationFile(): string
    {
        $root = getcwd();

        if (file_exists("$root/githooks.yml")) {
            $configFile = "$root/githooks.yml";
        } elseif (file_exists("$root/qa/githooks.yml")) {
            $configFile = "$root/qa/githooks.yml";
        } else {
            throw new ConfigurationFileNotFoundException();
        }

        return $configFile;
    }
}
