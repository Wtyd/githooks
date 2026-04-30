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
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Budget allocation with fixed/controllable/uncontrollable categories
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
            $override = $job->getCoresOverride();
            if ($override !== null) {
                // Explicit 'cores: N' — pin the allocation. If the capability is
                // controllable, FlowExecutor's applyThreadLimit() will propagate
                // this value to the tool's native flag.
                // Clamp to budget so admission can never reject the head forever
                // (FifoAdmission deadlocks if cores > budget).
                $cores = min($override, $budget);
                $fixedCost += $cores;
                $allocations[$job->getName()] = $cores;
                continue;
            }

            $capability = $job->getThreadCapability();
            if ($capability === null) {
                // No threading: counts as 1 core
                $fixedCost++;
                $allocations[$job->getName()] = 1;
            } elseif (!$capability->isControllable()) {
                // Has threads but we can't limit them (e.g. PHPStan).
                // Clamp the recorded cost to the total budget: the tool will
                // still spawn its N internal workers (applyThreadLimit is a
                // no-op here), but the pool's accounting cannot reserve more
                // than exists, otherwise FifoAdmission rejects forever.
                $cores = min($capability->getDefaultThreads(), $budget);
                $fixedCost += $cores;
                $uncontrollable[] = $job->getName();
                $allocations[$job->getName()] = $cores;
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
                // Clamp to budget: a capability declaring minimum > budget would
                // otherwise produce an allocation FifoAdmission can never satisfy.
                $allocations[$job->getName()] = min(max($min, $threadsPerJob), $budget);
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

        return new ThreadBudgetPlan($budget, $maxParallel, $allocations, $uncontrollable);
    }

    /**
     * Calculate how many jobs can run simultaneously within the budget.
     *
     * @param array<string, int> $allocations
     * @param JobAbstract[] $jobs
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $jobs reserved for future priority-based scheduling
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
