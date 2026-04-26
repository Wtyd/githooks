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

    private string $type;

    private ?int $exitCode;

    /** @var string[] */
    private array $paths;

    private bool $skipped;

    private ?string $skipReason;

    private ?string $stdout;

    private ?InputFilesPerJob $inputFiles;

    /**
     * @param string[] $paths
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Value object
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Value object with backward-compatible defaults
     */
    public function __construct(
        string $jobName,
        bool $success,
        string $output,
        string $executionTime,
        bool $fixApplied = false,
        ?string $command = null,
        string $type = '',
        ?int $exitCode = null,
        array $paths = [],
        bool $skipped = false,
        ?string $skipReason = null,
        ?string $stdout = null,
        ?InputFilesPerJob $inputFiles = null
    ) {
        $this->jobName = $jobName;
        $this->success = $success;
        $this->output = $output;
        $this->executionTime = $executionTime;
        $this->fixApplied = $fixApplied;
        $this->command = $command;
        $this->type = $type;
        $this->exitCode = $exitCode;
        $this->paths = $paths;
        $this->skipped = $skipped;
        $this->skipReason = $skipReason;
        $this->stdout = $stdout;
        $this->inputFiles = $inputFiles;
    }

    /**
     * Create a result for a job that was skipped (not executed).
     *
     * @param string[] $paths
     */
    public static function skipped(string $jobName, string $type, string $reason, array $paths = []): self
    {
        return new self($jobName, true, '', '0ms', false, null, $type, null, $paths, true, $reason);
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

    public function getType(): string
    {
        return $this->type;
    }

    public function getExitCode(): ?int
    {
        return $this->exitCode;
    }

    /** @return string[] */
    public function getPaths(): array
    {
        return $this->paths;
    }

    public function isSkipped(): bool
    {
        return $this->skipped;
    }

    public function getSkipReason(): ?string
    {
        return $this->skipReason;
    }

    /** Raw stdout from the process (separate from combined output). */
    public function getStdout(): ?string
    {
        return $this->stdout;
    }

    public function getInputFiles(): ?InputFilesPerJob
    {
        return $this->inputFiles;
    }

    /**
     * Return a new JobResult with the given inputFiles slice attached.
     */
    public function withInputFiles(?InputFilesPerJob $inputFiles): self
    {
        $clone = clone $this;
        $clone->inputFiles = $inputFiles;
        return $clone;
    }
}
