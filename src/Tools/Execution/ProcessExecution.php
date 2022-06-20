<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Tools\Execution;

use Wtyd\GitHooks\Tools\Errors;

class ProcessExecution extends ProcessExecutionAbstract
{
    public function runProcesses(): Errors
    {
        $toolName = array_keys($this->tools)[0];
        $process = reset($this->processes);

        $this->printer->line($this->tools[$toolName]->prepareCommand());

        try {
            $this->startProcess($process);
            $process->wait(function ($type, $buffer) {
                $this->printer->line($buffer);
            });

            $executionTime = $this->executionTime($process->getLastOutputTime(), $process->getStartTime());

            if ($process->isSuccessful()) {
                $this->printer->resultSuccess($this->getSuccessString($toolName, $executionTime));
            } else {
                if (!$this->tools[$toolName]->isIgnoreErrorsOnExit()) {
                    $this->errors->setError($toolName, $this->tools[$toolName]->getErrors());
                }
                $this->printer->resultError($this->getErrorString($toolName, $executionTime));
            }
        } catch (\Throwable $th) {
            $this->errors->setError('General', $th->getMessage());
            $this->printer->error($th->getMessage());
            $executionTime = $this->executionTime($process->getLastOutputTime(), $process->getStartTime());
            $this->printer->resultError($this->getErrorString($toolName, $executionTime));
        }
        return $this->errors;
    }
}
