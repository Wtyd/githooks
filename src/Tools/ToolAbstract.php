<?php

namespace GitHooks\Tools;

use GitHooks\Tools\Exception\ExecutableNotFoundException;

abstract class ToolAbstract
{
    //TODO Este atributo igual no tiene sentido ya que el nombre de las herramientas es el mismo comando con el que se ejecuta
    protected $executable;

    protected $installer;

    protected $exitCode;

    protected $exit;

    //TODO Creo que esta variable solo contiene errores al buscar el ejecutable
    protected $errors = '';

    public function __construct()
    {
        try {
            $this->executable = $this->executableFinder();
        } catch (ExecutableNotFoundException $th) {
            $this->errors = 'No se encuentra el comando ' . $this->executable . '. Instalalo mediante composer global require ' .  $this->installer;
        }
    }

    /**
     * Devuelve la primera versión del ejecutable que encuentra (como .phar, en local o global). Si no encuentra ninguna versión lanza excepción.
     *
     * @return string Ruta completa al ejecutable. Si no lo encuentra lanza una ExecutableNotFoundException
     */
    protected function executableFinder(): string
    {
        //TODO por un lado el composer puede estar a nivel global o puede ser un phar y por otro lado el ejecutable
        // $phar = 'php composer.phar show ' . $this->installer;
        $local = 'composer show ' . $this->installer;
        $global = 'composer global show ' . $this->installer;
        $root = getcwd();
        // if ($this->libraryCheck($phar)) {
        //     return  $this->executable . '.phar';
        // }

        if ($this->libraryCheck($local)) {
            return 'vendor/bin/' . $this->executable;
        }

        if ($this->libraryCheck($global)) {
            return $this->executable;
        }

        if ('php-parallel-lint/php-parallel-lint' === $this->installer) {
            $local = 'composer show jakub-onderka/php-parallel-lint';
            $global = 'composer global show jakub-onderka/php-parallel-lint';

            if ($this->libraryCheck($local)) {
                return $root . '/vendor/bin/' . $this->executable;
            }

            if ($this->libraryCheck($global)) {
                return $this->executable;
            }
        }

        throw ExecutableNotFoundException::forExec($this->executable);
    }

    /**
     * Comprueba que una librería está instalada ejecutando un composer show.
     *
     * @param string $composer  Comando composer (composer.phar, composer o composer global) show
     * @return boolean
     *
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     */
    protected function libraryCheck($composer): bool
    {
        $command = $composer . ' 2>&1';

        //composer show libreria/libreria 2>&1
        $exitArray =  $exitCode = null;
        exec($command, $exitArray, $exitCode);

        if ($exitCode === 0) {
            return true;
        }

        return false;
    }

    /**
     * Devuelve un array añadiendo el prefijo a cada uno de los elementos
     *
     * @param array $array
     * @param string $prefix
     * @return array
     */
    protected function addPrefixToArray(array $array, string $prefix)
    {
        return array_map(function ($arrayValues) use ($prefix) {
            return $prefix . $arrayValues;
        }, $array);
    }

    /**
     * Sustituye la / por \ cuando se invoca la app desde Windows
     *
     * @param string $path
     * @return string path
     */
    protected function routeCorrector(array $paths)
    {

        if (! $this->isWindows()) {
            return $paths;
        }

        $rightPaths = [];
        foreach ($paths as $path) {
            $rightPaths[] = str_replace('/', '\\', $path);
        }

        return $rightPaths;
    }

    /**
     * Comprueba si el sistema operativo desde el que se invoca la app es Windows.
     *
     * @return boolean
     */
    protected function isWindows()
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return true;
        }

        return false;
    }

    /**
     * Método donde se ejecuta la herramienta mediante exec. La herramienta no producirá ninguna salida.
     *
     * @return void
     */
    public function execute()
    {
        exec($this->prepareCommand(), $this->exit, $this->exitCode);
    }

    /**
     * Método donde se ejecuta la herramienta mediante passthru. Se mostrará por pantalla la salida de la herramienta en tiempo real.
     *
     * @return void
     */
    public function executeWithLiveOutput()
    {
        echo $this->prepareCommand() . "\n";
        passthru($this->prepareCommand(), $this->exitCode);
    }

    abstract protected function prepareCommand(): string;

    /**
     * Muestra los errores obtenidos por la herramienta. Es posible que una herramienta termine de forma inesperada.
     * En estos casos no se mostrara nada.
     *
     * @return void
     */
    public function printErrors()
    {
        if (is_array($this->getExit())) {
            foreach ($this->getExit() as $line) {
                echo "\n$line";
            }
        }
    }

    public function getExecutable()
    {
        return $this->executable;
    }

    public function getExitCode()
    {
        return $this->exitCode;
    }

    public function getExit()
    {
        return $this->exit;
    }

    public function getInstaller()
    {
        return $this->installer;
    }

    public function getErrors()
    {
        return $this->errors;
    }
}
