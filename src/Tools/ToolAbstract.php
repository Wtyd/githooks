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
    protected $exitCode;

    /**
     * @var array
     */
    protected $exit;

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
     * Devuelve la primera versión del ejecutable que encuentra (como .phar, en local o global). Si no encuentra ninguna versión lanza excepción.
     *
     * @return string Ruta completa al ejecutable. Si no lo encuentra lanza una ExecutableNotFoundException
     */
    protected function executableFinder(): string
    {
        //Step 1: Local
        //Search executable in vendor/bin
        // Case 1: tested
        $path = $this->searchInVendor();
        if(!empty($path)) return $path;

        //Search executable .phar in project root
        // Case 2: tested
        $path = $this->searchPhar();
        if(!empty($path)) return $path;

        //Step 2 : Global
        // Search executable globally
        
        // Unix command for linux and MacOS
        // Case 3: tested
        $command = 'which ' . $this->executable;
        $exitArray =  $exitCode = null;
        exec($command, $exitArray, $exitCode);
        if($exitCode == 0 && !empty($exitArray)){
            return $exitArray[0];
        }

        // TODO Pablo try where (Windows)
        // Windows command
        // Case 4: TODO
        // Option 1: Search in $PATH
        // How to search executables in windows path :
        // c:\> for %i in (cmd.exe) do @echo.   %~$PATH:i
        //     C:\WINDOWS\system32\cmd.exe
        // c:\> for %i in (python.exe) do @echo.   %~$PATH:i
        //     C:\Python25\python.exe

        // Option 2: powershell Get-Command or gcm as mentioned in another answer is equivalent to where

        // Option 3: where command
        //C:\Windows\System32\where.exe
        // where may return several values, the first one will be the one invoked
        // Remember that where.exe is not a shell builtin, you need to have %windir%\system32 on your %PATH% - which may not be the case, as using where suggests that you may be working on problems with your path

        // Option 4: Windows which implmentantion (batch file) : https://ss64.com/nt/syntax-which.html
        $command = 'where.exe ' . $this->executable;
        $exitArray =  $exitCode = null;
        exec($command, $exitArray, $exitCode);
        if($exitCode == 0 && !empty($exitArray)){
            return $exitArray[0];
        }

        //Step 3 : Composer dependency
        // TODO Pablo test composer show
        // Case 5: To test
        echo "composer \n";
        $local = 'composer show ' . $this->installer;
        if ($this->libraryCheck($local)) {
            echo 'VENDOR';
            return 'vendor/bin/' . $this->executable;
        }

        // Case 6: Tested
        $global = 'composer global show ' . $this->installer;
        
        if ($this->libraryCheck($global)) {
            return $this->executable;
        }

        //TODO Pablo to test
        $root = getcwd();
        if ('php-parallel-lint/php-parallel-lint' === $this->installer) {
            $local = 'composer show jakub-onderka/php-parallel-lint';
            $global = 'composer global show jakub-onderka/php-parallel-lint';

            // Repeat case 5 with old vendor
            if ($this->libraryCheck($local)) {
                return $root . '/vendor/bin/' . $this->executable;
            }

            // Repeat case 6 with old vendor
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

    /**
     * Devuelve la primera versión del ejecutable que encuentra (como .phar, en local o global). Si no encuentra ninguna versión lanza excepción.
     *
     * @return string Ruta completa al ejecutable. Si no lo encuentra lanza una ExecutableNotFoundException
     */
    private function searchInVendor() {
        $path = '';
        $command = 'vendor/bin/' . $this->executable . ' --version';
        $exitArray =  $exitCode = null;
        exec($command, $exitArray, $exitCode);
        if($exitCode == 0 && !empty($exitArray)){
            $path = 'vendor/bin/'. $this->executable;
        }
        return $path;
    }

    /**
     * Devuelve la primera versión del ejecutable que encuentra (como .phar, en local o global). Si no encuentra ninguna versión lanza excepción.
     *
     * @return string Ruta completa al ejecutable. Si no lo encuentra lanza una ExecutableNotFoundException
     */
    private function searchPhar() {
        $path = '';
        $command = 'php ' . $this->executable . '.phar --version';
        $exitArray =  $exitCode = null;
        exec($command, $exitArray, $exitCode); // 127 when error, 0 when OK
        if($exitCode == 0 && !empty($exitArray)){
            $path = 'php '. $this->executable . '.phar';
        }
        return $path;
    }
}
