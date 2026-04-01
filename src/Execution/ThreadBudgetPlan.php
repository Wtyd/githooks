<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

/**
 * Result of thread budget allocation: how many jobs to run in parallel
 * and how many threads each job should use.
 */
class ThreadBudgetPlan
{
    private int $maxParallelJobs;

    /** @var array<string, int> jobName => allocated threads */
    private array $allocations;

    /** @var string[] Jobs whose threads cannot be controlled via CLI */
    private array $uncontrollableJobs;

    /**
     * @param array<string, int> $allocations
     * @param string[] $uncontrollableJobs
     */
    public function __construct(int $maxParallelJobs, array $allocations, array $uncontrollableJobs = [])
    {
        $this->maxParallelJobs = $maxParallelJobs;
        $this->allocations = $allocations;
        $this->uncontrollableJobs = $uncontrollableJobs;
    }

    public function getMaxParallelJobs(): int
    {
        return $this->maxParallelJobs;
    }

    public function getAllocation(string $jobName): ?int
    {
        return $this->allocations[$jobName] ?? null;
    }

    /** @return array<string, int> */
    public function getAllocations(): array
    {
        return $this->allocations;
    }

    /** @return string[] */
    public function getUncontrollableJobs(): array
    {
        return $this->uncontrollableJobs;
    }
}
