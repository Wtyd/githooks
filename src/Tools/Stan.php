<?php

namespace Wtyd\GitHooks\Tools;

use Wtyd\GitHooks\Constants;
use Wtyd\GitHooks\Tools\Exception\ExitErrorException;
use Exception;

/**
 * Ejecuta la libreria phpstan/phpstan
 */
class Stan extends ToolAbstract
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

    public const OPTIONS = [self::PHPSTAN_CONFIGURATION_FILE, self::LEVEL, self::PATHS, self::MEMORY_LIMIT];

    /**
     * @var array
     */
    protected $args;

    public function __construct(array $configurationFile)
    {
        $this->installer = 'phpstan/phpstan';

        $this->executable = 'phpstan';

        $this->setArguments($configurationFile);

        parent::__construct();
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
        $config = '';
        if (!empty($this->args[self::PHPSTAN_CONFIGURATION_FILE])) {
            $config = '-c ' . $this->args[self::PHPSTAN_CONFIGURATION_FILE];
        }

        $level = '';
        if (!empty($this->args[self::LEVEL])) {
            $level = '-l ' . $this->args[self::LEVEL];
        }
        $paths = ''; // If path is empty phpStand will not work
        if (!empty($this->args[self::PATHS])) {
            $paths = implode(' ', $this->args[self::PATHS]);
        }

        $memoryLimit = '';
        if (!empty($this->args[self::MEMORY_LIMIT])) {
            $memoryLimit = '--memory-limit=' . $this->args[self::MEMORY_LIMIT];
        }

        $arguments = " analyse $config --no-progress --ansi $level $memoryLimit $paths";

        return $this->executable . $arguments;
    }

    /**
     * Lee los argumentos y los setea.
     *
     * @param array $configurationFile
     * @return void
     */
    public function setArguments($configurationFile)
    {
        if (!isset($configurationFile[Constants::PHPSTAN]) || empty($configurationFile[Constants::PHPSTAN])) {
            return;
        }
        $arguments = $configurationFile[Constants::PHPSTAN];

        if (!empty($arguments[self::PHPSTAN_CONFIGURATION_FILE])) {
            $this->args[self::PHPSTAN_CONFIGURATION_FILE] = $arguments[self::PHPSTAN_CONFIGURATION_FILE];
        }
        if (!empty($arguments[self::LEVEL])) {
            $this->args[self::LEVEL] = $arguments[self::LEVEL];
        }
        if (!empty($arguments[self::PATHS])) {
            $this->args[self::PATHS] = $this->routeCorrector($arguments[self::PATHS]);
        }
        if (!empty($arguments[self::MEMORY_LIMIT])) {
            $this->args[self::MEMORY_LIMIT] = $arguments[self::MEMORY_LIMIT];
        }
    }
}
