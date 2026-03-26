<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Tools\Process\Execution;

use Wtyd\GitHooks\Tools\Errors;
use Wtyd\GitHooks\Tools\Process\Process;
use Wtyd\GitHooks\Tools\Tool\ToolAbstract;
use Wtyd\GitHooks\Utils\GitStagerInterface;
use Wtyd\GitHooks\Utils\Printer;

abstract class ProcessExecutionAbstract
{
    /** @var \Wtyd\GitHooks\Utils\Printer */
    protected $printer;

    /** @var \Wtyd\GitHooks\Utils\GitStagerInterface */
    protected $gitStager;

    /** @var array<\Wtyd\GitHooks\Tools\Process\Process> */
    protected $processes = [];

    /** @var array<\Wtyd\GitHooks\Tools\Tool\ToolAbstract> */
    protected $tools;

    /** @var \Wtyd\GitHooks\Tools\Errors */
    protected $errors;

    /** @var int */
    protected $threads;

    public function __construct(Printer $printer, GitStagerInterface $gitStager)
    {
        $this->printer = $printer;
        $this->gitStager = $gitStager;
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
        return $this->runProcesses();
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
        $seconds = $endToolExecution - $startToolExecution;

        if ($seconds < 1) {
            return (int)($seconds * 1000) . 'ms';
        }

        if ($seconds < 60) {
            return number_format($seconds, 2) . 's';
        }

        $minutes = (int)($seconds / 60);
        $remainingSeconds = (int)($seconds % 60);

        return $minutes . 'm ' . $remainingSeconds . 's';
    }

    protected function createProcesses(): void
    {
        foreach ($this->tools as $key => $tool) {
            $this->processes[$key] = Process::fromShellCommandline($tool->prepareCommand());
            $this->processes[$key]->setTimeout(null);
        }
    }

    protected function startProcess(Process $process): void
    {
        $process->start();
    }

    /**
     * Checks if a tool applied fixes (e.g. phpcbf exit code 1) and re-stages files.
     *
     * @param ToolAbstract $tool
     * @param Process $process
     * @return bool True if a fix was applied and files were re-staged.
     */
    protected function handleFixApplied(ToolAbstract $tool, Process $process): bool
    {
        $exitCode = $process->getExitCode();

        if ($exitCode !== null && $tool->isFixApplied($exitCode)) {
            $this->gitStager->stageTrackedFiles();
            return true;
        }

        return false;
    }
}
