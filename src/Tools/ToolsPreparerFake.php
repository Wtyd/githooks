<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Tools;

use Wtyd\GitHooks\LoadTools\ExecutionMode;

class ToolsPreparerFake extends ToolsPreparer
{
    public function getStrategy(): ExecutionMode
    {
        return $this->executionFactory->__invoke($this->configurationFile->getExecution());
    }

    public function getExecutionMode(): string
    {
        return $this->configurationFile->getExecution();
    }
}
