<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowPlan;
use Wtyd\GitHooks\Jobs\ParatestJob;
use Wtyd\GitHooks\Output\NullOutputHandler;

/**
 * Decision table of the thread-budget distribution (factors.md
 * §thread-budget). INVARIANT: 1 ≤ allocation ≤ coresBudget and EVERY job in
 * the plan receives its allocation, regardless of the position of jobs with
 * an explicit `cores` override.
 *
 * Observability: resolveThreadBudget() runs before dry-run and
 * applyThreadLimit() rewrites the tool's native flag, so the built command
 * carries the exact allocation with no process spawned.
 */
class FlowExecutorThreadAllocationTest extends UnitTestCase
{
    /**
     * Boundary clamp == 1: an explicit `cores: 1` must reach the tool as
     * exactly 1. Kills the IncrementInteger mutant on the `max(1, ...)`
     * clamp of applyExplicitCoresOverrides (FlowExecutor:375), which would
     * silently raise the floor to 2.
     *
     * @test
     */
    public function explicit_cores_override_of_one_is_propagated_exactly(): void
    {
        $withOverride = $this->paratest('with_override', ['cores' => 1]);
        $plain = $this->paratest('plain');

        $this->executeDryRun([$withOverride, $plain], 2);

        $this->assertStringContainsString('--processes=1', $withOverride->buildCommand());
    }

    /**
     * Upper boundary: `cores` above the flow budget is clamped down to the
     * budget so admission can always satisfy the job.
     *
     * @test
     */
    public function explicit_cores_override_above_budget_is_clamped_to_budget(): void
    {
        $withOverride = $this->paratest('with_override', ['cores' => 5]);
        $plain = $this->paratest('plain');

        $this->executeDryRun([$withOverride, $plain], 2);

        $this->assertStringContainsString('--processes=2', $withOverride->buildCommand());
    }

    /**
     * Two-elements rule on the parallel allocation loop: a job placed AFTER
     * one that already holds an explicit override must still receive its
     * share of the budget plan. Kills the Continue_→break mutant at
     * FlowExecutor:394 (with break, the second job would keep the tool's
     * own default instead of the pool-accounted allocation).
     *
     * @test
     */
    public function jobs_after_an_override_job_still_receive_their_parallel_allocation(): void
    {
        $withOverride = $this->paratest('with_override', ['cores' => 1]);
        $plain = $this->paratest('plain');

        $this->executeDryRun([$withOverride, $plain], 2);

        $this->assertStringContainsString('--processes=1', $plain->buildCommand());
    }

    /**
     * Same two-elements rule on the sequential path (processes = 1). Kills
     * the Continue_→break mutant at FlowExecutor:421.
     *
     * @test
     */
    public function jobs_after_an_override_job_still_receive_their_sequential_allocation(): void
    {
        $withOverride = $this->paratest('with_override', ['cores' => 1]);
        $plain = $this->paratest('plain');

        $this->executeDryRun([$withOverride, $plain], 1);

        $this->assertStringContainsString('--processes=1', $plain->buildCommand());
    }

    private function paratest(string $name, array $config = []): ParatestJob
    {
        return new ParatestJob(new JobConfiguration($name, 'paratest', $config));
    }

    /**
     * @param \Wtyd\GitHooks\Jobs\JobAbstract[] $jobs
     */
    private function executeDryRun(array $jobs, int $processes): void
    {
        $executor = new FlowExecutor(new NullOutputHandler());
        $executor->execute(new FlowPlan('test', $jobs, new OptionsConfiguration(false, $processes)), true);
    }
}
