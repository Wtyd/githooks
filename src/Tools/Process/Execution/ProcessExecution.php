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
        $tool = $this->tools[$toolName];
        $displayName = $tool->getDisplayName();
        $process = $this->processes[$toolName];

        $this->printer->line($tool->prepareCommand());

        try {
            $this->startProcess($process);
            $process->wait(function ($type, $buffer) {
                $this->printer->rawLine($buffer);
            });

            $executionTime = $this->executionTime($process->getLastOutputTime(), $process->getStartTime());

            if ($process->isSuccessful()) {
                $this->printer->resultSuccess($this->getSuccessString($displayName, $executionTime));
            } elseif ($this->handleFixApplied($tool, $process)) {
                $this->printer->resultSuccess($this->getSuccessString($displayName, $executionTime));
            } else {
                if (!$tool->isIgnoreErrorsOnExit()) {
                    $this->errors->setError($toolName, $process->getOutput());
                }
                $this->printer->resultError($this->getErrorString($displayName, $executionTime));
            }
        } catch (\Throwable $th) {
            $this->errors->setError('General', $th->getMessage());
            $this->printer->error($th->getMessage());
            $executionTime = $this->executionTime($process->getLastOutputTime(), $process->getStartTime());
            $this->printer->resultError($this->getErrorString($displayName, $executionTime));
        }
        return $this->errors;
    }
}
