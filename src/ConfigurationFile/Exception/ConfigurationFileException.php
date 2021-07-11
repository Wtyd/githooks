<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\ConfigurationFile\Exception;

use Wtyd\GitHooks\ConfigurationFile\ConfigurationFile;

class ConfigurationFileException extends \RuntimeException implements ConfigurationFileInterface
{
    /**
     * @var ConfigurationFile
     */
    protected $configurationFile;

    public static function forFile(ConfigurationFile $configurationFile): ConfigurationFileException
    {
        $exception = new self('The configuration file has some errors');

        $exception->configurationFile = $configurationFile;

        return $exception;
    }

    public function getConfigurationFile(): ConfigurationFile
    {
        return $this->configurationFile;
    }
}
