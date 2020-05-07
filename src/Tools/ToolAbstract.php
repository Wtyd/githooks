<?php

namespace GitHooks\Tools;

use GitHooks\Tools\Exception\ExecutableNotFoundException;

abstract class ToolAbstract
{
    //TODO Este atributo igual no tiene sentido ya que el nombre de las herramientas es el mismo comando con el que se ejecuta
    /**
     * @var string
     */
    protected $executable;

    /**
     * @var string
     */
    protected $installer;

    /**
     * @var int
     */
    protected $exitCode = -1;

    /**
     * @var array
     */
    protected $exit = '';

    //TODO Creo que esta variable solo contiene errores al buscar el ejecutable
    /**
     * @var string
     */
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
     * Devuelve la primera versión del ejecutable que encuentra. La prioridad de búsqueda es local > .phar > global . Si no encuentra ninguna versión lanza excepción.
     *
     * @return string Ruta completa al ejecutable. Si no lo encuentra lanza una ExecutableNotFoundException
     */
    protected function executableFinder(): string
    {
        //Step 1: Local
        //Search executable in vendor/bin
        $command = 'vendor/bin/' . $this->executable . ' --version';
        if ($this->libraryCheck($command)) {
            return 'vendor/bin/' . $this->executable;
        }

        //Search executable .phar in project root
        $command = 'php ' . $this->executable . '.phar --version';
        if ($this->libraryCheck($command)) {
            return 'php ' . $this->executable . '.phar';
        }
        //Step 2 : Composer dependency
        $global = 'composer global show ' . $this->installer;
        if ($this->libraryCheck($global)) {
            return $this->executable;
        }
        //Step 3 : Global
        // Search executable globally
        // Unix command for linux and MacOS
        $command = 'which ' . $this->executable;
        $exitArray =  $exitCode = null;
        exec($command, $exitArray, $exitCode);
        if ($exitCode == 0 && !empty($exitArray)) {
            return $exitArray[0];
        }
        throw ExecutableNotFoundException::forExec($this->executable);
    }

    /**
     * Comprueba que una librería está instalada ejecutando el comando de consola que se pase por parametro.
     *
     * @param string $command  Comando para intentar encontrar el ejecutable de la libreria
     * @return boolean
     *
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     */
    protected function libraryCheck($command): bool
    {
        $command = $command . ' 2>&1';

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
     * @param array $paths
     * @return array path
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
        $command = $this->prepareCommand();
        echo  $command . "\n";
        passthru($command, $this->exitCode);
    }

    abstract protected function prepareCommand(): string;

    public function getExecutable(): string
    {
        return $this->executable;
    }

    public function getExitCode(): int
    {
        return $this->exitCode;
    }

    public function getExit(): array
    {
        return $this->exit;
    }

    public function getInstaller(): string
    {
        return $this->installer;
    }

    public function getErrors(): string
    {
        return $this->errors;
    }
}
