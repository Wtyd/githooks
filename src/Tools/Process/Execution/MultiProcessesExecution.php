<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Tools\Process\Execution;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;
use Wtyd\GitHooks\Tools\Errors;
use Wtyd\GitHooks\Tools\Process\Process;

class MultiProcessesExecution extends ProcessExecutionAbstract
{
    /** @var array<Process> */
    protected $runnedProcesses = [];

    /** @var array<Process> */
    protected $runningProcesses = [];

    /** @var int */
    protected $numberOfRunnedProcesses = 0;

    /** @var array<string, array{displayName: string, success: bool}> */
    protected $toolResults = [];

    public function runProcesses(): Errors
    {
        $startCommandExecution = microtime(true);

        try {
            $totalProcesses = count($this->processes);

            do {
                try {
                    $this->addProcessToQueue();
                    foreach ($this->runningProcesses as $toolName => $process) {
                        // $process->checkTimeout(); // timeout is null for now
                        // throw new \Exception('asdfasdf');
                        if ($process->isTerminated()) {
                            $this->numberOfRunnedProcesses = $this->finishExecution($process, $toolName);
                        }
                    }
                } catch (ProcessTimedOutException $th) {
                    $toolName = (string)array_search($th->getProcess(), $this->processes);
                    $this->numberOfRunnedProcesses = $this->finishExecution($th->getProcess(), $toolName, $th->getMessage());
                } catch (ProcessFailedException $th) {
                    $toolName = (string)array_search($th->getProcess(), $this->processes);
                    $this->numberOfRunnedProcesses = $this->finishExecution($th->getProcess(), $toolName);
                } catch (Throwable $th) {
                    $this->errors->setError('Tool crash', $th->getMessage());
                }
            } while ($totalProcesses > $this->numberOfRunnedProcesses);
        } catch (\Throwable $th) {
            $this->errors->setError('General', $th->getMessage());
        }
        $endCommandExecution = microtime(true);
        $executionTime = $this->executionTime($endCommandExecution, $startCommandExecution);
        $this->printer->line("Total Runtime: $executionTime seconds");
        $this->printSummary();
        return $this->errors;
    }

    /**
     * Add process to queue of running processes
     */
    protected function addProcessToQueue(): void
    {
        foreach ($this->processes as $toolName => $process) {
            if (count($this->runningProcesses) === $this->threads) {
                break;
            }
            if (!in_array($process, $this->runningProcesses) && !in_array($process, $this->runnedProcesses)) {
                $this->startProcess($process);
                $this->runningProcesses[$toolName] = $process;
            }
        }
    }

    /**
     * Finish process execution
     * ¡¡¡Warning with egde case!!! Sometimes the process finishes with an error message in the normal output
     * (sintaxys errors in Phpmd 2.9 or minus, for example) but the error output is empty.
     * @param Process $process
     * @param string $toolName Name of the process.
     * @param string $exceptionMessage
     * @return int Number of runned processes.
     */
    protected function finishExecution(Process $process, string $toolName, string $exceptionMessage = null): int
    {
        $this->runnedProcesses[$toolName] = $process;
        $tool = $this->tools[$toolName];
        $displayName = $tool->getDisplayName();
        $executionTime = $this->executionTime($process->getLastOutputTime(), $process->getStartTime());

        if ($process->isSuccessful()) {
            $this->printer->resultSuccess($this->getSuccessString($displayName, $executionTime));
            $this->toolResults[$toolName] = ['displayName' => $displayName, 'success' => true];
        } elseif ($this->handleFixApplied($tool, $process)) {
            $this->printer->resultSuccess($this->getSuccessString($displayName, $executionTime));
            $this->toolResults[$toolName] = ['displayName' => $displayName, 'success' => true];
        } else {
            $errorMessage = $exceptionMessage ?? $process->getErrorOutput();
            $errorMessage = empty($errorMessage) ? $process->getOutput() : $errorMessage;
            if (!$tool->isIgnoreErrorsOnExit()) {
                $this->errors->setError($toolName, $errorMessage);
            }

            $this->printer->resultError($this->getErrorString($displayName, $executionTime));
            $this->printer->framedErrorBlock($displayName, $errorMessage);
            $this->toolResults[$toolName] = ['displayName' => $displayName, 'success' => false];
        }

        $this->printer->emptyLine();
        unset($this->runningProcesses[$toolName]);

        return count($this->runnedProcesses);
    }

    protected function printSummary(): void
    {
        $orderedResults = [];
        $passed = 0;
        foreach ($this->tools as $toolName => $tool) {
            if (isset($this->toolResults[$toolName])) {
                $result = $this->toolResults[$toolName];
            } else {
                $result = ['displayName' => $tool->getDisplayName(), 'success' => false];
            }
            $orderedResults[] = $result;
            if ($result['success']) {
                $passed++;
            }
        }
        $total = count($this->tools);
        $toolList = ($passed < $total) ? $orderedResults : [];
        $this->printer->summary($passed, $total, $toolList);
    }
}
