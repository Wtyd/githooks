<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Configuration\MemoryBudgetConfiguration;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\ProcessPool;
use Wtyd\GitHooks\Jobs\CustomJob;
use Wtyd\GitHooks\Output\NullOutputHandler;

/**
 * `FlowExecutor::buildProcessPool` wires 2D bin-packing admission only when the
 * flow declares a memory-budget AND at least one job has a short-form `memory:`
 * reservation (REQ-009 / REQ-020). See factors.md §4.
 *
 * `AdmissionContext::fits()` already tests the 1D/2D decision; this verifies the
 * gate that *connects* the budget to the pool. Kills the FlowExecutor:612 mutant
 * (TrueValue `$hasReservation = true` → `false`): with the mutant, a budgeted
 * flow with reservations would silently fall back to 1D (ignoring memory).
 */
class FlowExecutorBuildPoolMemoryTest extends UnitTestCase
{
    /** @test */
    public function pool_runs_2d_when_budget_and_a_reservation_are_present(): void
    {
        $jobs = [
            new CustomJob(new JobConfiguration('reserves', 'custom', ['script' => 'x', 'memory' => 600])),
            new CustomJob(new JobConfiguration('plain', 'custom', ['script' => 'y'])),
        ];
        $options = new OptionsConfiguration(
            false,
            2,
            null,
            'full',
            '',
            [],
            null,
            new MemoryBudgetConfiguration(1000, null) // binPackingReference = 1000
        );

        $pool = $this->buildPool($jobs, $options);

        $this->assertSame(1000, $pool->getMemoryBudget());
    }

    /** @test */
    public function pool_runs_1d_when_budget_present_but_no_reservation(): void
    {
        $jobs = [
            new CustomJob(new JobConfiguration('plain_a', 'custom', ['script' => 'x'])),
            new CustomJob(new JobConfiguration('plain_b', 'custom', ['script' => 'y'])),
        ];
        $options = new OptionsConfiguration(
            false,
            2,
            null,
            'full',
            '',
            [],
            null,
            new MemoryBudgetConfiguration(1000, null)
        );

        $pool = $this->buildPool($jobs, $options);

        $this->assertNull($pool->getMemoryBudget(), 'no reservation → 1D admission, budget not wired');
    }

    /**
     * @param \Wtyd\GitHooks\Jobs\JobAbstract[] $jobs
     */
    private function buildPool(array $jobs, OptionsConfiguration $options): ProcessPool
    {
        $executor = new class (new NullOutputHandler()) extends FlowExecutor {
            /** @param \Wtyd\GitHooks\Jobs\JobAbstract[] $jobs */
            public function buildPoolForTest(array $jobs, OptionsConfiguration $options): ProcessPool
            {
                return $this->buildProcessPool(2, 2, $jobs, $options);
            }
        };

        return $executor->buildPoolForTest($jobs, $options);
    }
}
