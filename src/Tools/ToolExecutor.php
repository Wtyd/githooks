<?php

namespace GitHooks\Tools;

use GitHooks\Tools\Exception\ExitErrorException;
use GitHooks\Tools\Exception\ModifiedButUnstagedFilesException;
use GitHooks\Utils\Printer;

class ToolExecutor
{
    const OK = 0;

    const KO = 1;

    /**
     * @var Printer
     */
    protected $printer;

    public function __construct(Printer $printer)
    {
        $this->printer = $printer;
    }

    /**
     * Ejecuta las herramientas y muestra un mensaje de OK o KO según el análisis de la herramienta.
     *
     * @param array $tools
     * @param boolean $isLiveOutput Si es true ejecutará la herramienta mostrando la salida en tiempo real como si la ejecutaramos manualmente por consola.
     *                  Si es false la ejecución de la herramienta no muestra ninguna.
     * @return integer $exitCode El codigo de salida (por defecto 0) cambia a 1 cuando una herrmienta falla por cualquier motivo
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    public function __invoke(array $tools, bool $isLiveOutput = false): int
    {
        $exitCode = self::OK;
        foreach ($tools as $tool) {
            $startToolTime = microtime(true);
            try {
                if ($this->errorsFindingExecutable($tool->getErrors())) {
                    $this->printer->generalFail($tool->getErrors());
                    return self::KO;
                }

                if ($isLiveOutput) {
                    $tool->executeWithLiveOutput();
                } else {
                    $tool->execute();
                }
                $endToolTime = microtime(true);
                $executionToolTime = ($endToolTime - $startToolTime);
                $time = number_format($executionToolTime, 2);
                if ($tool->getExitCode() === self::OK) {
                    $message = $this->getSuccessString($tool->getExecutable(), $time);
                    $this->printer->resultSuccess($message);
                } else {
                    $this->printErrors($tool);
                    $exitCode = self::KO;
                    $message = $this->getErrorString($tool->getExecutable(), $time);
                    $this->printer->resultError($message);
                }
            } catch (ModifiedButUnstagedFilesException $ex) {
                $this->printErrors($tool);
                $endToolTime = microtime(true);
                $executionToolTime = ($endToolTime - $startToolTime);
                $time = number_format($executionToolTime, 2);
                //TODO cambiar $tool->getExecutable() por el nombre de la herramienta para que aparezcan cosas como var/www/html/distribucion/vendor/zataca/githooks/src/Tools/../../../bin/phpcbf - OK. Time: 8.07
                $exitCode = self::KO;
                $message = $this->getSuccessString($tool->getExecutable(), $time) . '. Se han modificado algunos ficheros. Por favor, añádelos al stage y vuelve a commitear.';
                $this->printer->resultWarning($message);
            } catch (ExitErrorException $th) {
                $this->printErrors($tool);
                //TODO a lo mejor cuando revienta una herramienta queremos mostrar el stacktraces para poder corregir la configuración de la herramienta. Esto viene de PHPStan
                $endToolTime = microtime(true);
                $executionToolTime = ($endToolTime - $startToolTime);
                $exitCode = self::KO;
                $time = number_format($executionToolTime, 2);
                $message = $this->getErrorString($tool->getExecutable(), $time);
                $this->printer->resultError($message);
            } catch (\Throwable $th) {
                $this->printErrors($tool);
                $exitCode = self::KO;
                $message = "Error en la ejecución de $tool->getExecutable(). \n";
                $this->printer->line($th->getMessage());
                $this->printer->resultError($message);
            }
        }

        return $exitCode;
    }

    /**
     * Muestra los errores obtenidos por la herramienta. Es posible que una herramienta termine de forma inesperada.
     * En estos casos no se mostrara nada.
     *
     * @return void
     */
    public function printErrors($tool)
    {
        if (is_array($tool->getExit())) {
            foreach ($tool->getExit() as $line) {
                $this->printer->line($line);
            }
        }
    }

    protected function getErrorString(string $tool, string $time): string
    {
        return $tool . ' - KO. Time: ' . $time;
    }

    protected function getSuccessString(string $tool, string $time): string
    {
        return $tool . ' - OK. Time: ' . $time;
    }

    protected function errorsFindingExecutable(string $errors): bool
    {
        if (empty($errors)) {
            return false;
        }

        return true;
    }
}
