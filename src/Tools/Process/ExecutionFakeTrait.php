<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Tools\Process;

/**
 * Trait for testing purposes. Gives public visibility for some methods and properties.
 */
trait ExecutionFakeTrait
{
    /** @var array<ProcessFake> */
    protected $processes = [];

    /** @var array<\Wtyd\GitHooks\Tools\Tool\ToolAbstact> */
    protected $toolsThatMustFail = [];

    /** @var array<\Wtyd\GitHooks\Tools\Tool\ToolAbstact> */
    protected $failedToolsByException = [];

    /** @var array<\Wtyd\GitHooks\Tools\Tool\ToolAbstact> */
    protected $failedToolsByFoundedErrors = [];

    /** @var array<\Wtyd\GitHooks\Tools\Tool\ToolAbstact> */
    protected $failedToolsByFoundedErrorsWithExitCode0 = [];

    /** @var array<\Wtyd\GitHooks\Tools\Tool\ToolAbstact> */
    protected $toolsWithTimeout = [];

    protected function createProcesses(): void
    {
        foreach ($this->tools as $key => $tool) {
            $this->processes[$key] = new ProcessFake(explode(' ', $tool->prepareCommand()));
        }
        foreach ($this->toolsThatMustFail as $tool) {
            $this->processes[$tool]->setFail();
        }

        foreach ($this->failedToolsByException as $tool) {
            $this->processes[$tool]->setFailByException();
        }

        foreach ($this->failedToolsByFoundedErrors as $tool) {
            $this->processes[$tool]->setFailByFoundedErrors();
        }

        foreach ($this->failedToolsByFoundedErrorsWithExitCode0 as $tool) {
            $this->processes[$tool]->setFailByErrorsWithExitCode0();
        }
        foreach ($this->toolsWithTimeout as $tool) {
            $this->processes[$tool]->triggerTimeout();
        }
    }

    public function setToolsThatMustFail(array $toolsThatMustFail): void
    {
        $this->toolsThatMustFail = $toolsThatMustFail;
    }

    public function failedToolsByException(array $failedToolsByException): void
    {
        $this->failedToolsByException = $failedToolsByException;
    }

    public function failedToolsByFoundedErrors(array $failedToolsByFoundedErrors): void
    {
        $this->failedToolsByFoundedErrors = $failedToolsByFoundedErrors;
    }

    public function failedToolsByFoundedErrorsWithExitCode0(array $failedToolsByFoundedErrorsWithExitCode0): void
    {
        $this->failedToolsByFoundedErrorsWithExitCode0 = $failedToolsByFoundedErrorsWithExitCode0;
    }

    public function setToolsWithTimeout(array $toolsWithTimeout): void
    {
        $this->toolsWithTimeout = $toolsWithTimeout;
    }
}
