<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

use Wtyd\GitHooks\Execution\Memory\MemoryStats;

class FlowResult
{
    private string $flowName;

    /** @var JobResult[] */
    private array $jobResults;

    private string $totalTime;

    private int $peakEstimatedThreads;

    private int $threadBudget;

    private string $executionMode;

    private ?InputFilesResolution $inputFiles;

    /** @var string[]|null Normal flow names after meta-flow expansion (multi-flow runs only) */
    private ?array $expandedFlows;

    private ?EffectiveOptionsResolution $effectiveOptions;

    private ?TimeBudgetState $timeBudgetState;

    private ?MemoryBudgetState $memoryBudgetState = null;

    private ?MemoryStats $memoryStats = null;

    /**
     * @param JobResult[] $jobResults
     * @param string[]|null $expandedFlows
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Immutable result aggregator.
     */
    public function __construct(
        string $flowName,
        array $jobResults,
        string $totalTime,
        int $peakEstimatedThreads = 0,
        int $threadBudget = 0,
        string $executionMode = 'full',
        ?InputFilesResolution $inputFiles = null,
        ?array $expandedFlows = null,
        ?EffectiveOptionsResolution $effectiveOptions = null,
        ?TimeBudgetState $timeBudgetState = null
    ) {
        $this->flowName = $flowName;
        $this->jobResults = $jobResults;
        $this->totalTime = $totalTime;
        $this->peakEstimatedThreads = $peakEstimatedThreads;
        $this->threadBudget = $threadBudget;
        $this->executionMode = $executionMode;
        $this->inputFiles = $inputFiles;
        $this->expandedFlows = $expandedFlows;
        $this->effectiveOptions = $effectiveOptions;
        $this->timeBudgetState = $timeBudgetState;
    }

    public function getFlowName(): string
    {
        return $this->flowName;
    }

    /** @return JobResult[] */
    public function getJobResults(): array
    {
        return $this->jobResults;
    }

    public function isSuccess(): bool
    {
        foreach ($this->jobResults as $result) {
            if (!$result->isSuccess()) {
                return false;
            }
        }

        if ($this->memoryBudgetState !== null && $this->memoryBudgetState->isFailed()) {
            return false;
        }

        if ($this->timeBudgetState !== null && $this->timeBudgetState->isFailed()) {
            return false;
        }

        return true;
    }

    public function getTotalTime(): string
    {
        return $this->totalTime;
    }

    public function getFailedCount(): int
    {
        return count(array_filter(
            $this->jobResults,
            fn(JobResult $result) => !$result->isSuccess() && !$result->isSkipped()
        ));
    }

    public function getPassedCount(): int
    {
        return count(array_filter(
            $this->jobResults,
            fn(JobResult $result) => $result->isSuccess() && !$result->isSkipped()
        ));
    }

    public function getPeakEstimatedThreads(): int
    {
        return $this->peakEstimatedThreads;
    }

    public function getThreadBudget(): int
    {
        return $this->threadBudget;
    }

    public function getExecutionMode(): string
    {
        return $this->executionMode;
    }

    public function getSkippedCount(): int
    {
        return count(array_filter($this->jobResults, fn(JobResult $result) => $result->isSkipped()));
    }

    public function getInputFiles(): ?InputFilesResolution
    {
        return $this->inputFiles;
    }

    /**
     * @return string[]|null Normal flow names after meta-flow expansion;
     *                      null for `flow X` and single-flow degenerate runs.
     */
    public function getExpandedFlows(): ?array
    {
        return $this->expandedFlows;
    }

    public function getEffectiveOptions(): ?EffectiveOptionsResolution
    {
        return $this->effectiveOptions;
    }

    public function getTimeBudgetState(): ?TimeBudgetState
    {
        return $this->timeBudgetState;
    }

    public function getMemoryBudgetState(): ?MemoryBudgetState
    {
        return $this->memoryBudgetState;
    }

    public function setMemoryBudgetState(?MemoryBudgetState $state): void
    {
        $this->memoryBudgetState = $state;
    }

    public function getMemoryStats(): ?MemoryStats
    {
        return $this->memoryStats;
    }

    public function setMemoryStats(?MemoryStats $stats): void
    {
        $this->memoryStats = $stats;
    }
}
