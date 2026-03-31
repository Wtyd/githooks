<?php

declare(strict_types=1);

namespace Tests\Doubles;

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
    protected $setFailByFoundedErrorsInNormalOutput = [];

    /** @var array<\Wtyd\GitHooks\Tools\Tool\ToolAbstact> */
    protected $toolsWithTimeout = [];

    /** @var array<string> Tools that simulate having applied a fix (e.g. phpcbf exit code 1) */
    protected $toolsWithFixApplied = [];

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

        foreach ($this->setFailByFoundedErrorsInNormalOutput as $tool) {
            $this->processes[$tool]->setFailByFoundedErrorsInNormalOutput();
        }
        foreach ($this->toolsWithTimeout as $tool) {
            $this->processes[$tool]->triggerTimeout();
        }
        foreach ($this->toolsWithFixApplied as $tool) {
            $this->processes[$tool]->setFixApplied();
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

    public function setFailByFoundedErrorsInNormalOutput(array $setFailByFoundedErrorsInNormalOutput): void
    {
        $this->setFailByFoundedErrorsInNormalOutput = $setFailByFoundedErrorsInNormalOutput;
    }

    public function setToolsWithTimeout(array $toolsWithTimeout): void
    {
        $this->toolsWithTimeout = $toolsWithTimeout;
    }

    /**
     * @param array<string> $toolsWithFixApplied
     * @return void
     */
    public function setToolsWithFixApplied(array $toolsWithFixApplied): void
    {
        $this->toolsWithFixApplied = $toolsWithFixApplied;
    }

    protected function addProcessToQueue(): void
    {
        if ($this->failFastTriggered) {
            return;
        }

        foreach ($this->processes as $toolName => $process) {
            if (count($this->runningProcesses) === $this->threads) {
                break;
            }
            if (!in_array($process, $this->runningProcesses) && !in_array($process, $this->runnedProcesses)) {
                $this->startProcess($process);
                $this->runningProcesses[$toolName] = $process;
            }
        }
        parent::addProcessToQueue();
    }
}
