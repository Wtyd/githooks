<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Tools\Execution;

use Wtyd\GitHooks\Tools\Tool\ToolAbstract;

class ProcessExecutionFactoryFake extends ProcessExecutionFactoryAbstract
{

    /** @inheritDoc */
    public function create(string $tool): ProcessExecutionAbstract
    {

        $processExecution = null;
        if (ToolAbstract::ALL_TOOLS === $tool) {
            $processExecution = $this->container->makeWith(MultiProcessesExecutionFake::class, [$this->printer]);
        } else {
            $processExecution = $this->container->makeWith(ProcessExecutionFake::class, [$this->printer]);
        }

        return $processExecution;
    }
}
