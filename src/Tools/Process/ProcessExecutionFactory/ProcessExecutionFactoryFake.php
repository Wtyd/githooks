<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Tools\Process\ProcessExecutionFactory;

use Tests\Doubles\MultiProcessesExecutionFake;
use Tests\Doubles\ProcessExecutionFake;
use Wtyd\GitHooks\Tools\Process\Execution\ProcessExecutionAbstract;
use Wtyd\GitHooks\Registry\ToolRegistry;

class ProcessExecutionFactoryFake extends ProcessExecutionFactoryAbstract
{

    /** @inheritDoc */
    public function create(string $tool): ProcessExecutionAbstract
    {

        $processExecution = null;
        if (ToolRegistry::ALL_TOOLS === $tool) {
            $processExecution = $this->container->makeWith(MultiProcessesExecutionFake::class, [$this->printer, $this->gitStager]);
        } else {
            $processExecution = $this->container->makeWith(ProcessExecutionFake::class, [$this->printer, $this->gitStager]);
        }

        return $processExecution;
    }
}
