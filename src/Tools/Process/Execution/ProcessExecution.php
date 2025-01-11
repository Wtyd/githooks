<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Tools\Process\Execution;

use Wtyd\GitHooks\Tools\Errors;

class ProcessExecution extends ProcessExecutionAbstract
{
    public function runProcesses(): Errors
    {
        if (empty($this->tools)) {
            return $this->errors;
        }
        $toolName = array_keys($this->tools)[0];
        $process = $this->processes[$toolName];

        $this->printer->line($this->tools[$toolName]->prepareCommand());

        try {
            $this->startProcess($process);
            $process->wait(function ($type, $buffer) {
                $this->printer->rawLine($buffer);
            });

            $executionTime = $this->executionTime($process->getLastOutputTime(), $process->getStartTime());

            if ($process->isSuccessful()) {
                $this->printer->resultSuccess($this->getSuccessString($toolName, $executionTime));
            } else {
                if (!$this->tools[$toolName]->isIgnoreErrorsOnExit()) {
                    $this->errors->setError($toolName, $process->getOutput());
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
