<?php

namespace Wtyd\GitHooks\Tools;

use Wtyd\GitHooks\Tools\Exception\ExecutableNotFoundException;

abstract class ToolAbstract
{
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
    protected $exit = [];

    /**
     * @var string
     */
    protected $errors = '';

    public function __construct()
    {
        // $a = 10;
        try {
            $this->executable = $this->executableFinder();
        } catch (ExecutableNotFoundException $th) {
            $this->errors = 'No se encuentra el comando ' . $this->executable . '. Instalalo mediante composer global require ' .  $this->installer;
        }
    }

    /**
     * Devuelve la primera versión del ejecutable que encuentra. La prioridad de búsqueda es local > .phar > global.
     * Si no encuentra ninguna versión lanza excepción.
     *
     * @return string Ruta completa al ejecutable. Si no lo encuentra lanza una ExecutableNotFoundException
     */
    protected function executableFinder(): string
    {
        //Step 1: Local
        //Search executable in vendor/bin
        $localPaths = $this->routeCorrector(['vendor/bin/' . $this->executable])[0];
        $command = $localPaths  . ' --version';
        if ($this->libraryCheck($command)) {
            return $localPaths;
        }

        //Step 2: Local as .phar
        //Search executable .phar in project root
        $command = 'php ' . $this->executable . '.phar --version';
        if ($this->libraryCheck($command)) {
            return 'php ' . $this->executable . '.phar';
        }

        //Step 3: Global installation with global access
        $global = $this->executable . ' --version';
        if ($this->libraryCheck($global)) {
            return $this->executable;
        }

        //Step 4: Global installation without global access
        if (!$this->isWindows()) {
            $command = 'which ' . $this->executable;
            $exitArray =  $exitCode = null;
            exec($command, $exitArray, $exitCode);
            if ($exitCode == 0 && !empty($exitArray)) {
                return $exitArray[0];
            }
        } else {
            $command = 'where ' . $this->executable;
            $exitArray =  $exitCode = null;
            exec($command, $exitArray, $exitCode);
            if ($exitCode == 0 && !empty($exitArray)) {
                return $exitArray[1];
            }

            // $command = '(dir 2>&1 *`|echo CMD)';
            // $exitArray =  $exitCode = null;
            // exec($command, $exitArray, $exitCode);

            // var_dump($exitArray[0]);
            //In CMD
            // if ('CMD' === $exitArray[0]) {
            //     $command = 'where ' . $this->executable;
            //     $exitArray =  $exitCode = null;
            //     exec($command, $exitArray, $exitCode);
            //     if ($exitCode == 0 && !empty($exitArray)) {
            //         return $exitArray[1];
            //     }
            // } else {
            //     //In PowerShell
            //     //En PS se puede encontrar con $command = Get-Command -showcommandinfo phpcs y $command.Definition ya que $command sería un SwitchParameter que es algo aprecido a un Enum
            //     //pero para generalizar lo hacemos con where.exe que también funciona en GitBash.
            //     $command = 'where.exe ' . $this->executable;
            //     $exitArray =  $exitCode = null;
            //     exec($command, $exitArray, $exitCode);
            //     if ($exitCode == 0 && !empty($exitArray)) {
            //         return $exitArray[1];
            //     }
            // }
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
        if (!$this->isWindows()) {
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
     * This method is run by 'vendor/bin/githooks tool:...' commands. The output of the tool/s will be displayed in real time.
     * This method has the key word 'final' because is equal for any tool.
     *
     * @return void
     */
    final public function executeWithLiveOutput()
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
