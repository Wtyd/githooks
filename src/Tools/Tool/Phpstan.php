<?php

namespace Wtyd\GitHooks\Tools\Tool;

use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;

/**
 * Ejecuta la libreria phpstan/phpstan
 */
class Phpstan extends ToolAbstract
{
    /**
     * @var string PHPSTAN_CONFIGURATION_FILE Tag que indica la ruta del fichero de configuración de phpstan-phpqa.neon en el fichero de configuracion .yml
     */
    public const PHPSTAN_CONFIGURATION_FILE = 'config';

    /**
     * @var string LEVEL Tag que indica el nivel de analisis de phpstan en el fichero de configuracion .yml. Su valor es un entero del 1 al 9.
     */
    public const LEVEL = 'level';

    /**
     * @var string PATHS Tag que indica sobre qué carpetas se debe ejecutar el análisis de phpstan en el fichero de configuracion .yml
     */
    public const PATHS = 'paths';

    /**
     * @var string MEMORY_LIMIT Tag que indica de cuánta memoria puede disponer la herramienta en el fichero de configuracion .yml
     */
    public const MEMORY_LIMIT = 'memory-limit';

    public const OPTIONS = [
        self::EXECUTABLE_PATH_OPTION,
        self::PHPSTAN_CONFIGURATION_FILE,
        self::LEVEL,
        self::MEMORY_LIMIT,
        self::OTHER_ARGS_OPTION,
        self::PATHS,
    ];

    /**
     * @var array
     */
    protected $args;

    public function __construct(ToolConfiguration $toolConfiguration)
    {
        $this->executable = self::PHPSTAN;

        $this->setArguments($toolConfiguration->getToolConfiguration());
    }

    /**
     * Crea la cadena de argumentos que ejecutará phpstan
     * -c (--configuration): .neon file with extra configuration
     * -l (--level): rule level from 0 (default) to 8
     * --memory-limit: increase memory limit by default of php. Example values: 1G, 1M 1024M
     * paths: directories with code to analyse.
     *
     * @return string Example return: analyse -c=phpstan.neon --no-progress --ansi -l=1 --memory-limit=1G ./src1 ./src2
     */
    protected function prepareCommand(): string
    {
        $command = '';
        foreach (self::OPTIONS as $option) {
            if (empty($this->args[$option])) {
                continue;
            }

            switch ($option) {
                case self::EXECUTABLE_PATH_OPTION:
                    $command .= $this->args[self::EXECUTABLE_PATH_OPTION] . ' analyse';
                    break;
                case self::PATHS:
                    $command .= ' ' . implode(',', $this->args[$option]);
                    break;
                case self::PHPSTAN_CONFIGURATION_FILE:
                    $command .= ' -c ' . $this->args[self::PHPSTAN_CONFIGURATION_FILE];
                    break;
                case self::LEVEL:
                    $command .= ' -l ' . $this->args[self::LEVEL];
                    break;
                case self::MEMORY_LIMIT:
                    $command .= ' --memory-limit=' . $this->args[self::MEMORY_LIMIT];
                    break;
                default:
                    $command .= ' ' . $this->args[self::OTHER_ARGS_OPTION];
                    break;
            }
        }

        return $command;
    }

    public function setArguments(array $configurationFile): void
    {
        foreach ($configurationFile as $key => $value) {
            if (!empty($value)) {
                // $this->args[$key] = $this->multipleRoutesCorrector($value);
                $this->args[$key] = $value;
            }
        }
        if (empty($this->args[self::EXECUTABLE_PATH_OPTION])) {
            $this->args[self::EXECUTABLE_PATH_OPTION] = self::PHPSTAN;
        }
        // $this->executablePath = $this->routeCorrector($configurationFile[self::EXECUTABLE_PATH_OPTION] ?? self::PHPSTAN);
        // if (!empty($configurationFile[self::PHPSTAN_CONFIGURATION_FILE])) {
        //     $this->args[self::PHPSTAN_CONFIGURATION_FILE] = $configurationFile[self::PHPSTAN_CONFIGURATION_FILE];
        // }
        // if (!empty($configurationFile[self::LEVEL])) {
        //     $this->args[self::LEVEL] = $configurationFile[self::LEVEL];
        // }
        // if (!empty($configurationFile[self::PATHS])) {
        //     $this->args[self::PATHS] = $this->multipleRoutesCorrector($configurationFile[self::PATHS]);
        // }
        // if (!empty($configurationFile[self::MEMORY_LIMIT])) {
        //     $this->args[self::MEMORY_LIMIT] = $configurationFile[self::MEMORY_LIMIT];
        // }
    }
}
