<?php

namespace Wtyd\GitHooks\Tools;

use Wtyd\GitHooks\Tools\Tool\ToolAbstract;
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
     * @param boolean $withLiveOutput True for run one tool in with real time response. False for run two or more tools at same time.
     * @return Errors $exitCode 0 for success, 1 for error.
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    public function __invoke(array $tools, bool $withLiveOutput = false): Errors
    {
        $errors = new Errors();
        foreach ($tools as $tool) {
            $startToolExecution = microtime(true);
            try {
                $tool->execute($withLiveOutput);

                $endToolExecution = microtime(true);
                $executionTime = $this->executionTime($endToolExecution, $startToolExecution);
                if ($tool->getExitCode() === 0) {
                    $this->printer->resultSuccess($this->getSuccessString($tool::NAME, $executionTime));
                } else {
                    $errors->setError($tool::NAME, $tool->getErrors());
                    $this->printErrors($tool);
                    $this->printer->resultError($this->getErrorString($tool::NAME, $executionTime));
                }
            } catch (\Throwable $th) {
                $errors->setError($tool::NAME, $th->getMessage());
                $this->printErrors($tool);
                $this->printer->line($th->getMessage());
                $this->printer->resultError('Error when running ' . $tool::NAME . ' ');
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
