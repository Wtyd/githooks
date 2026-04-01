<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

class FlowResult
{
    private string $flowName;

    /** @var JobResult[] */
    private array $jobResults;

    private string $totalTime;

    private int $peakEstimatedThreads;

    private int $threadBudget;

    /**
     * @param JobResult[] $jobResults
     */
    public function __construct(
        string $flowName,
        array $jobResults,
        string $totalTime,
        int $peakEstimatedThreads = 0,
        int $threadBudget = 0
    ) {
        $this->flowName = $flowName;
        $this->jobResults = $jobResults;
        $this->totalTime = $totalTime;
        $this->peakEstimatedThreads = $peakEstimatedThreads;
        $this->threadBudget = $threadBudget;
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
        return true;
    }

    public function getTotalTime(): string
    {
        return $this->totalTime;
    }

    public function getFailedCount(): int
    {
        return count(array_filter($this->jobResults, fn(JobResult $result) => !$result->isSuccess()));
    }

    public function getPassedCount(): int
    {
        return count(array_filter($this->jobResults, fn(JobResult $result) => $result->isSuccess()));
    }

    public function getPeakEstimatedThreads(): int
    {
        return $this->peakEstimatedThreads;
    }

    public function getThreadBudget(): int
    {
        return $this->threadBudget;
    }
}
