<?php

namespace GitHooks;

use GitHooks\Exception\ConfigurationFileNotFoundException;
use GitHooks\Exception\ParseConfigurationFileException;
use GitHooks\Exception\ToolsIsEmptyException;
use GitHooks\Exception\ToolsNotFoundException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class Configuration
{
    /**
     * Lee el fichero githooks.yml y lo transforma en un array asociativo. Al leer el fichero se pueden dar diversos problemas:
     * 1. El fichero no existe o no tiene la extensión correcta o no cumple el formato .yml. En este caso se lanza una ParseConfigurationFileException.
     * 2. Si el fichero está vacío o no existe la clave Tools se lanza una ToolsNotFoundException.
     * 3. Existe la clave Tools pero está vacía. Se lanza una ToolsIsEmptyException.
     * 4. Existe la clave Tools y contiene herramientas. Se devuelve el fichero transformado a array asociativo.
     *
     * @return array Los valores del fichero githooks.yml en formato de array asociativo.
     */
    public function readFile(): array
    {
        $configurationFilePath = $this->findConfigurationFile();

        try {
            $configurationFile = Yaml::parseFile($configurationFilePath);
        } catch (ParseException $exception) {
            throw ParseConfigurationFileException::forMessage($exception->getMessage());
        }

        if (!is_array($configurationFile) || !array_key_exists(Constants::TOOLS, $configurationFile)) {
            throw ToolsNotFoundException::forFile($configurationFilePath);
        }

        if (empty($configurationFile[Constants::TOOLS])) {
            throw ToolsIsEmptyException::forFile($configurationFilePath);
        }

        return $configurationFile;
    }

    /**
     * Searchs configuration file 'githooks.yml' on root path and qa/ directory
     *
     * @return string The path of configuration file
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
