<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Tools\Process\ProcessExecutionFactory;

use Wtyd\GitHooks\Tools\Process\Execution\MultiProcessesExecution;
use Wtyd\GitHooks\Tools\Process\Execution\ProcessExecution;
use Wtyd\GitHooks\Tools\Process\Execution\ProcessExecutionAbstract;
use Wtyd\GitHooks\Tools\Tool\ToolAbstract;

class ProcessExecutionFactory extends ProcessExecutionFactoryAbstract
{

    /** @inheritDoc */
    public function create(string $tool): ProcessExecutionAbstract
    {
        $processExecution = null;
        if (ToolAbstract::ALL_TOOLS === $tool) {
            $processExecution = new MultiProcessesExecution($this->printer);
        } else {
            $processExecution = new ProcessExecution($this->printer);
        }

        return $processExecution;
    }
}
