<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

class JobResult
{
    private string $jobName;

    private bool $success;

    private string $output;

    private string $executionTime;

    private bool $fixApplied;

    private ?string $command;

    /** @SuppressWarnings(PHPMD.BooleanArgumentFlag) Value object */
    public function __construct(
        string $jobName,
        bool $success,
        string $output,
        string $executionTime,
        bool $fixApplied = false,
        ?string $command = null
    ) {
        $this->jobName = $jobName;
        $this->success = $success;
        $this->output = $output;
        $this->executionTime = $executionTime;
        $this->fixApplied = $fixApplied;
        $this->command = $command;
    }

    public function getJobName(): string
    {
        return $this->jobName;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getOutput(): string
    {
        return $this->output;
    }

    public function getExecutionTime(): string
    {
        return $this->executionTime;
    }

    public function isFixApplied(): bool
    {
        return $this->fixApplied;
    }

    public function getCommand(): ?string
    {
        return $this->command;
    }
}
