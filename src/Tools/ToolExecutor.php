<?php

namespace Wtyd\GitHooks\Tools;

use Wtyd\GitHooks\Tools\Exception\ModifiedButUnstagedFilesException;
use Wtyd\GitHooks\Utils\Printer;

class ToolExecutor
{
    /**
     * @var Printer
     */
    protected $printer;

    public function __construct(Printer $printer)
    {
        $this->printer = $printer;
    }

    /**
     * It executes the tools. If all tools run successfully it returns an empty array. Else returns an array with the errors.
     *
     * @param array $tools
     * @param boolean $withLiveOutput Si es true ejecutará la herramienta mostrando la salida en tiempo real como si la ejecutaramos manualmente por consola.
     *                  Si es false la ejecución de la herramienta no muestra ninguna.
     * @return Errors $exitCode El codigo de salida (por defecto 0) cambia a 1 cuando una herrmienta falla por cualquier motivo
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    public function __invoke(array $tools, bool $withLiveOutput = false): Errors
    {
        $errors = new Errors();
        foreach ($tools as $tool) {
            $startToolExecution = microtime(true);
            try {
                if ($withLiveOutput) {
                    $tool->executeWithLiveOutput();
                } else {
                    $tool->execute();
                }

                $endToolExecution = microtime(true);
                $executionTime = $this->executionTime($endToolExecution, $startToolExecution);
                if ($tool->getExitCode() === 0) {
                    $this->printer->resultSuccess($this->getSuccessString($tool->getExecutable(), $executionTime));
                } else {
                    $errors->setError($tool->getExecutable(), $tool->getErrors());
                    $this->printErrors($tool);
                    $this->printer->resultError($this->getErrorString($tool->getExecutable(), $executionTime));
                }
            } catch (ModifiedButUnstagedFilesException $ex) {
                $endToolExecution = microtime(true);
                $this->printErrors($tool);
                $message = $this->getErrorString($tool->getExecutable(), $this->executionTime($endToolExecution, $startToolExecution)) . '. Se han modificado algunos ficheros. Por favor, añádelos al stage y vuelve a commitear.';
                $this->printer->resultWarning($message);
                $errors->setError($tool->getExecutable(), $message);
            } catch (\Throwable $th) {
                $errors->setError($tool->getExecutable(), $th->getMessage());
                $this->printErrors($tool);
                $this->printer->line($th->getMessage());
                $this->printer->resultError("Error when running $tool->getExecutable().");
            }
        }
        return $errors;
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
