<?php

namespace GitHooks\Tools;

use Error;
use GitHooks\Tools\Exception\ModifiedButUnstagedFilesException;
use GitHooks\Utils\Printer;

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
     * @param boolean $isLiveOutput Si es true ejecutará la herramienta mostrando la salida en tiempo real como si la ejecutaramos manualmente por consola.
     *                  Si es false la ejecución de la herramienta no muestra ninguna.
     * @return Errors $exitCode El codigo de salida (por defecto 0) cambia a 1 cuando una herrmienta falla por cualquier motivo
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    public function __invoke(array $tools, bool $isLiveOutput = false): Errors
    {
        //TODO en un primer intento de refactor he cambiado el exitCode por Errors. El problema es que executeWithLiveOutput sigue haciendo un exit
        //en la clase invocadora ToolCommandExecutor lo que hace que no se puedan hacer tests de los commands.
        // La idea es que sea el Command en cuestion quien, en funcion de Errors haga un exit(0) o exit(1) embebido dentro de un método que pueda doblar ya que
        // que si no no hay forma de hacer pruebas. Crear 2 estrategías una para cuando se $isLiveOutput es false y otra true. Una pintará por pantalla los errroes y la otroa no
        $errors = new Errors();
        foreach ($tools as $tool) {
            $startToolExecution = microtime(true);
            try {
                if ($this->errorsFindingExecutable($tool->getErrors())) {
                    $this->printer->generalFail($tool->getErrors());
                    $errors->setError($tool->getExecutable(), $tool->getErrors());
                }

                if ($isLiveOutput) {
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
                $this->printer->resultError("Error en la ejecución de $tool->getExecutable().");
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
