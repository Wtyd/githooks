<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Tools\Execution;

use Wtyd\GitHooks\Tools\Tool\ToolAbstract;

class ProcessExecutionFactory extends ProcessExecutionFactoryAbstract
{

    /** @inheritDoc */
    public function create(string $tool, array $tools, int $threds): ProcessExecutionAbstract
    {
        $processExecution = null;
        if (ToolAbstract::ALL_TOOLS === $tool) {
            $processExecution = new MultiProcessesExecution($this->printer, $tools, $threds);
        } else {
            $processExecution = new ProcessExecution($this->printer, $tools, $threds);
        }

        return $processExecution;
    }
}
