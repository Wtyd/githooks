<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Tools\Process\Execution;

use Wtyd\GitHooks\Tools\Errors;
use Wtyd\GitHooks\Tools\Process\Process;
use Wtyd\GitHooks\Tools\Tool\ToolAbstract;
use Wtyd\GitHooks\Utils\Printer;

abstract class ProcessExecutionAbstract
{
    /** @var \Wtyd\GitHooks\Utils\Printer */
    protected $printer;

    /** @var array<\Wtyd\GitHooks\Tools\Process\Process> */
    protected $processes = [];

    /** @var array<\Wtyd\GitHooks\Tools\Tool\ToolAbstract> */
    protected $tools;

    /** @var \Wtyd\GitHooks\Tools\Errors */
    protected $errors;

    /** @var int */
    protected $threads;

    public function __construct(Printer $printer)
    {
        $this->printer = $printer;
        $this->errors = new Errors();
    }

    /**
     * Run the $tools on the number of $threads in parallel.
     *
     * @param array<\Wtyd\GitHooks\Tools\Tool\ToolAbstact> $tools
     * @param integer $threads
     * @return Errors
     */
    public function execute(array $tools, int $threads): Errors
    {
        $this->tools = $tools;
        $this->threads = $threads;

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
     * Prints the errors found by the tool. In case it ends unexpectedly it will not print anything.
     *
     * @return void
     */
    public function printErrors(ToolAbstract $tool): void
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
            $this->processes[$key]->setTimeout(null); // without timeout
            // TODO customize timeout
        }
    }

    protected function startProcess(Process $process): void
    {
        $process->start();
    }
}
