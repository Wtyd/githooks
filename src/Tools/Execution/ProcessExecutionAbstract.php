<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Tools\Execution;

use Wtyd\GitHooks\Tools\Errors;
use Wtyd\GitHooks\Tools\Tool\ToolAbstract;
use Wtyd\GitHooks\Utils\Printer;

abstract class ProcessExecutionAbstract
{
    /** @var \Wtyd\GitHooks\Utils\Printer */
    protected $printer;

    /** @var array<\Wtyd\GitHooks\Tools\Execution\Process> */
    protected $processes = [];

    /** @var array<\Wtyd\GitHooks\Tools\Tool\ToolAbstract> */
    protected $tools;

    /** @var \Wtyd\GitHooks\Tools\Errors */
    protected $errors;

    /** @var int */
    protected $threads;

    public function __construct(Printer $printer, array $tools, int $threads)
    {
        $this->printer = $printer;
        $this->tools = $tools;
        $this->threads = $threads;
        $this->errors = new Errors();
    }

    public function execute(): Errors
    {
        $this->createProcesses();

        try {
            return $this->runProcesses();
        } catch (\Throwable $th) {
            $this->errors->setError('General', $th->getMessage());
            return $this->errors;
        }
    }

    abstract protected function runProcesses(): Errors;

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
     * @param ?float $endToolExecution
     * @param float $startToolExecution
     * @return string Total tool execution time
     */
    protected function executionTime(?float $endToolExecution, float $startToolExecution): string
    {
        return number_format($endToolExecution - $startToolExecution, 2);
    }

    protected function createProcesses(): void
    {
        foreach ($this->tools as $key => $tool) {
            $this->processes[$key] = new Process(explode(' ', $tool->prepareCommand()));
            $this->processes[$key]->setTimeout(300); // 5 minutes
        }
    }

    protected function startProcess(Process $process): void
    {
        $process->start();
    }
}
