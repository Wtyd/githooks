<?php

namespace GitHooks\Tools;

use GitHooks\Constants;
use GitHooks\Tools\Exception\ExitErrorException;
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

    public const OPTIONS = [self::PHPSTAN_CONFIGURATION_FILE, self::LEVEL];

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

    public function execute()
    {

        exec($this->prepareCommand(), $this->exit, $this->exitCode);

        $this->exitCode = 0;

        $this->isCrashed();

        $this->exit = [];
    }

    protected function prepareCommand(): string
    {
        $config = '';
        if (!empty($this->args[self::PHPSTAN_CONFIGURATION_FILE])) {
            $config = $this->args[self::PHPSTAN_CONFIGURATION_FILE];
        }

        $level = '';
        if (!empty($this->args[self::LEVEL])) {
            $level = '-l ' . $this->args[self::LEVEL];
        }
        $paths = ''; // If path is empty phpStand will not work
        if (!empty($this->args[self::PATHS])) {
            $paths = implode(" ", $this->args[self::PATHS]);
        }

        $arguments = " analyse $config --no-progress -n $level $paths";
        return $this->executable . $arguments;
    }

    /**
     * PhpStan crashea (en lugar de dar error) por ejemplo cuando una clase que implementa una interfaz no implementa todos los métodos de dicha interfaz.
     * Lo mismo ocurre cuando una clase hereda de una clase abstracta y no implementa alguno de los métodos abstractos de la clase padre.
     * Para capturar el crasheo simplemente verificamos que el array de salida es un array vacio.
     *
     * @return void
     */
    public function isCrashed()
    {
        if (empty($this->exit)) {
            $this->exitCode = 1;
            throw ExitErrorException::forExit($this->exit);
        }
    }

    /**
     * Lee los argumentos y los setea. Si vienen vacios se establecen unos por defecto.
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

        // TODO Pablo: isset y array_key_exist
        if (!empty($arguments[self::PHPSTAN_CONFIGURATION_FILE])) {
            $this->args[self::PHPSTAN_CONFIGURATION_FILE] = $arguments[self::PHPSTAN_CONFIGURATION_FILE];
        }
        if (!empty($arguments[self::LEVEL])) {
            $this->args[self::LEVEL] = $arguments[self::LEVEL];
        }
        if (!empty($arguments[self::PATHS])) {
            $this->args[self::PATHS] = $arguments[self::PATHS];
        }
    }
}
