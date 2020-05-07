<?php

namespace GitHooks\Tools;

use GitHooks\Tools\Exception\ExitErrorException;
use GitHooks\Tools\Exception\ModifiedButUnstagedFilesException;
use GitHooks\Utils\Printer;

class ToolExecutor
{
    public const OK = 0;

    public const KO = 1;

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
            $startToolExecution = microtime(true);
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

                $endToolExecution = microtime(true);
                $executionTime = $this->executionTime($endToolExecution, $startToolExecution);
                if ($tool->getExitCode() === self::OK) {
                    $this->printer->resultSuccess($this->getSuccessString($tool->getExecutable(), $executionTime));
                } else {
                    $exitCode = self::KO;
                    $this->printErrors($tool);
                    $this->printer->resultError($this->getErrorString($tool->getExecutable(), $executionTime));
                }
            } catch (ModifiedButUnstagedFilesException $ex) {
                $endToolExecution = microtime(true);
                $exitCode = self::KO;
                $this->printErrors($tool);
                $message = $this->getErrorString($tool->getExecutable(), $this->executionTime($endToolExecution, $startToolExecution)) . '. Se han modificado algunos ficheros. Por favor, añádelos al stage y vuelve a commitear.';
                $this->printer->resultWarning($message);
            } catch (\Throwable $th) {
                $exitCode = self::KO;
                $this->printErrors($tool);
                $this->printer->line($th->getMessage());
                $this->printer->resultError("Error en la ejecución de $tool->getExecutable().");
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
    public function printErrors(ToolAbstract $tool)
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

    /**
     * Returns the time difference formatted to two decimal places
     *
     * @param float $endToolExecution
     * @param float $startToolExecution
     * @return string Total tool execution time
     */
    protected function executionTime(float $endToolExecution, float $startToolExecution): string
    {
        return number_format($endToolExecution - $startToolExecution, 2);
    }
}
