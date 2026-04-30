<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

use LogicException;

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
     * @throws LogicException  If any allocation exceeds the budget. FifoAdmission
     *                         would reject such a job forever (cores > free_max
     *                         is unsatisfiable) and the executor would deadlock.
     *                         Producers must clamp before constructing the plan.
     */
    public function __construct(
        int $budget,
        int $maxParallelJobs,
        array $allocations,
        array $uncontrollableJobs = []
    ) {
        foreach ($allocations as $jobName => $cores) {
            if ($cores > $budget) {
                throw new LogicException(sprintf(
                    "ThreadBudgetPlan invariant violated: job '%s' allocated %d cores "
                        . "but total budget is %d. FifoAdmission would reject the job forever; "
                        . "the producer must clamp allocations to the budget.",
                    $jobName,
                    $cores,
                    $budget
                ));
            }
        }
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
