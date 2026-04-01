<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

use Wtyd\GitHooks\Jobs\JobAbstract;

/**
 * Distributes a total CPU budget across jobs, respecting each tool's
 * threading capability. Jobs without thread support count as 1 core each.
 */
class ThreadBudgetAllocator
{
    /**
     * @param int $budget Total cores available (from options.processes)
     * @param JobAbstract[] $jobs All jobs in the flow
     */
    public function allocate(int $budget, array $jobs): ThreadBudgetPlan
    {
        if ($budget < 1) {
            $budget = 1;
        }

        $threadableJobs = [];
        $fixedCost = 0;
        $uncontrollable = [];
        $allocations = [];

        foreach ($jobs as $job) {
            $capability = $job->getThreadCapability();
            if ($capability === null) {
                // No threading: counts as 1 core
                $fixedCost++;
                $allocations[$job->getName()] = 1;
            } elseif (!$capability->isControllable()) {
                // Has threads but we can't limit them (e.g. PHPStan)
                $fixedCost += $capability->getDefaultThreads();
                $uncontrollable[] = $job->getName();
                $allocations[$job->getName()] = $capability->getDefaultThreads();
            } else {
                $threadableJobs[] = $job;
            }
        }

        $remainingBudget = max(0, $budget - $fixedCost);
        $threadableCount = count($threadableJobs);

        if ($threadableCount > 0 && $remainingBudget > 0) {
            $threadsPerJob = max(1, (int) floor($remainingBudget / $threadableCount));
            foreach ($threadableJobs as $job) {
                $capability = $job->getThreadCapability();
                $min = $capability !== null ? $capability->getMinimumThreads() : 1;
                $allocations[$job->getName()] = max($min, $threadsPerJob);
            }
        } else {
            // No budget left — each threadable job gets minimum (1)
            foreach ($threadableJobs as $job) {
                $allocations[$job->getName()] = 1;
            }
        }

        // Max parallel jobs: sum of allocated threads cannot exceed budget
        // Each running job uses its allocated threads
        $maxParallel = $this->calculateMaxParallel($budget, $allocations, $jobs);

        return new ThreadBudgetPlan($maxParallel, $allocations, $uncontrollable);
    }

    /**
     * Calculate how many jobs can run simultaneously within the budget.
     *
     * @param array<string, int> $allocations
     * @param JobAbstract[] $jobs
     */
    private function calculateMaxParallel(int $budget, array $allocations, array $jobs): int
    {
        // Sort allocations ascending (cheapest jobs first) to maximize parallelism
        $costs = array_values($allocations);
        sort($costs);

        $parallel = 0;
        $used = 0;
        foreach ($costs as $cost) {
            if ($used + $cost <= $budget) {
                $parallel++;
                $used += $cost;
            } else {
                break;
            }
        }

        return max(1, $parallel);
    }
}
