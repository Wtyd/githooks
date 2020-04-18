<?php

namespace GitHooks;

use GitHooks\Exception\ParseConfigurationFileException;
use GitHooks\Exception\ToolsIsEmptyException;
use GitHooks\Exception\ToolsNotFoundException;
use Illuminate\Container\Container;
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
     * @param string $filePath Ruta al fichero de configuración.
     * @return array Los valores del fichero githooks.yml en formato de array asociativo.
     */
    public function readFile(string $filePath): array
    {

        try {
            $configurationFile = Yaml::parseFile($filePath);
        } catch (ParseException $exception) {
            throw ParseConfigurationFileException::forMessage($exception->getMessage());
        }

        if (!is_array($configurationFile) || !array_key_exists(Constants::TOOLS, $configurationFile)) {
            throw ToolsNotFoundException::forFile($filePath);
        }

        if (empty($configurationFile[Constants::TOOLS])) {
            throw ToolsIsEmptyException::forFile($filePath);
        }

        return $configurationFile;
    }

    /**
     * Valida el formato de $configurationFile
     *
     * @param array $configurationFile. Fichero de configuración.
     * @return ConfigurationErrors POPO que contiene un array de errores y uno de warnings.
     */
    public function check($configurationFile): ConfigurationErrors
    {
        $errors = [];
        $warnings = [];
        //No existen claves distintas en la raíz de las que deben existir

        if (array_key_exists(Constants::OPTIONS, $configurationFile)) {
            if ($configurationFile[Constants::OPTIONS] == null) {
                $warnings[] = 'La etiqueta ' . Constants::OPTIONS . ' está vacía';
            } else {
                $expected = [Constants::SMART_EXECUTION];

                foreach (array_keys($configurationFile[Constants::OPTIONS]) as $key) {
                    if (! in_array($key, $expected)) {
                        $errors[] = "El elemento $key no es una opción válida.";
                    }
                }
            }
        }

        foreach ($configurationFile[Constants::TOOLS] as $tool) {
            if (Constants::CHECK_SECURITY === $tool) {
                continue;
            }

            if (! array_key_exists($tool, Constants::TOOL_LIST)) {
                $errors[] = "La herramienta $tool no está soportada por GitHooks.";
            } elseif (! array_key_exists($tool, $configurationFile)) {
                $errors[] = "La herramienta $tool no está configurada.";
            } else {
                $totalErrors = $this->checkConfiguration($configurationFile[$tool], Constants::TOOL_LIST[$tool]::OPTIONS, $tool);

                $errors = $this->addErrors($errors, $totalErrors[Constants::ERRORS]);
                $warnings = $this->addErrors($warnings, $totalErrors[Constants::WARNINGS]);
            }
        }

        return new ConfigurationErrors($errors, $warnings);
    }

    /**
     * Verifica que los $configurationArguments se corresponda con alguno de los $expectedValues.
     *
     * @param array $configurationArguments Argumentos para la herramienta leídos del fichero de configuración.
     * @param array $expectedValues Argumentos válidos de la herramienta.
     * @param string $tool El nombre de la herramienta.
     * @return array Array bidimensional donde la key ERRORS es una array de errores y la key WARNINGS es un array de warnings.
     */
    protected function checkConfiguration(array $configurationArguments, array $expectedValues, string $tool): array
    {
        $errors = [];
        $warnings = [];

        foreach (array_keys($configurationArguments) as $key) {
            if (! in_array($key, $expectedValues)) {
                $errors[] = "El argumento $key no es válido para la herramienta " . $tool;
            }
        }

        return [Constants::ERRORS => $errors, Constants::WARNINGS => $warnings];
    }

    /**
     * Concatena $newErrors a $errors. Se pueden dar 3 casos:
     * 1. $errors está vacío por lo que devuelvo $newErrors (da igual que esté también vacío).
     * 2. $errors NO está vacío y $newErrors tampoco. Hacemos un merge de los dos arrays.
     * 3. $errors No está vacío y $newErrors sí lo está. Devolvemos $errors.
     *
     * @param array $errors Los errores totales hasta el momento.
     * @param array $newErrors Los nuevos errores que hay que sumar
     * @return array La concatenación de los errores hasta el momento y de los nuevos.
     */
    protected function addErrors(array $errors, array $newErrors): array
    {
        if (empty($errors)) {
            return $newErrors;
        } elseif (! empty($newErrors)) {
            return array_merge($errors, $newErrors);
        }

        return $errors;
    }
}
