<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use Mockery;
use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Execution\ThreadBudgetAllocator;
use Wtyd\GitHooks\Execution\ThreadCapability;
use Wtyd\GitHooks\Jobs\JobAbstract;

class ThreadBudgetAllocatorTest extends UnitTestCase
{
    private function makeJob(
        string $name,
        ?ThreadCapability $capability = null,
        ?int $coresOverride = null
    ): JobAbstract {
        $config = new JobConfiguration($name, 'phpcs', []);
        $job = Mockery::mock(JobAbstract::class)->makePartial();
        $job->shouldReceive('getName')->andReturn($name);
        $job->shouldReceive('getThreadCapability')->andReturn($capability);
        $job->shouldReceive('getCoresOverride')->andReturn($coresOverride);
        return $job;
    }

    /** @test */
    function it_assigns_1_thread_to_jobs_without_capability()
    {
        $allocator = new ThreadBudgetAllocator();
        $jobs = [
            $this->makeJob('phpmd_src'),
            $this->makeJob('phpcpd_all'),
        ];

        $plan = $allocator->allocate(4, $jobs);

        $this->assertSame(1, $plan->getAllocation('phpmd_src'));
        $this->assertSame(1, $plan->getAllocation('phpcpd_all'));
        $this->assertSame(2, $plan->getMaxParallelJobs());
    }

    /** @test */
    function it_distributes_budget_among_threadable_jobs()
    {
        $allocator = new ThreadBudgetAllocator();
        $jobs = [
            $this->makeJob('phpcs_all', new ThreadCapability('parallel', 8)),
            $this->makeJob('psalm_src', new ThreadCapability('threads', 4)),
        ];

        $plan = $allocator->allocate(4, $jobs);

        // 0 fixed cost, 4 budget for 2 threadable = 2 each
        $this->assertSame(2, $plan->getAllocation('phpcs_all'));
        $this->assertSame(2, $plan->getAllocation('psalm_src'));
        $this->assertSame(2, $plan->getMaxParallelJobs());
    }

    /** @test */
    function it_accounts_for_fixed_cost_jobs()
    {
        $allocator = new ThreadBudgetAllocator();
        $jobs = [
            $this->makeJob('phpmd_src'),  // fixed: 1
            $this->makeJob('phpcs_all', new ThreadCapability('parallel', 8)),
            $this->makeJob('lint', new ThreadCapability('jobs', 10)),
        ];

        $plan = $allocator->allocate(4, $jobs);

        // fixed=1, remaining=3 for 2 threadable = 1 each (floor(3/2)=1)
        $this->assertSame(1, $plan->getAllocation('phpmd_src'));
        $this->assertSame(1, $plan->getAllocation('phpcs_all'));
        $this->assertSame(1, $plan->getAllocation('lint'));
    }

    /** @test */
    function it_handles_uncontrollable_jobs()
    {
        $allocator = new ThreadBudgetAllocator();
        $jobs = [
            $this->makeJob('phpstan_src', new ThreadCapability('_internal', 4, 1, false)),
            $this->makeJob('phpcs_all', new ThreadCapability('parallel', 1)),
        ];

        $plan = $allocator->allocate(6, $jobs);

        // phpstan: uncontrollable, costs 4. remaining=2 for phpcs
        $this->assertSame(4, $plan->getAllocation('phpstan_src'));
        $this->assertSame(2, $plan->getAllocation('phpcs_all'));
        $this->assertContains('phpstan_src', $plan->getUncontrollableJobs());
    }

    /** @test */
    function it_gives_minimum_1_thread_when_budget_exhausted()
    {
        $allocator = new ThreadBudgetAllocator();
        $jobs = [
            $this->makeJob('a'),
            $this->makeJob('b'),
            $this->makeJob('c'),
            $this->makeJob('d'),
            $this->makeJob('e', new ThreadCapability('parallel', 8)),
        ];

        $plan = $allocator->allocate(2, $jobs);

        // 4 fixed = 4, budget=2 → remaining=0 → threadable gets 1
        $this->assertSame(1, $plan->getAllocation('e'));
    }

    /** @test */
    function it_handles_budget_of_1()
    {
        $allocator = new ThreadBudgetAllocator();
        $jobs = [
            $this->makeJob('phpcs', new ThreadCapability('parallel', 4)),
            $this->makeJob('phpmd'),
        ];

        $plan = $allocator->allocate(1, $jobs);

        $this->assertSame(1, $plan->getMaxParallelJobs());
    }

    /** @test */
    function it_gives_all_budget_to_single_threadable_job()
    {
        $allocator = new ThreadBudgetAllocator();
        $jobs = [
            $this->makeJob('phpcs_all', new ThreadCapability('parallel', 1)),
        ];

        $plan = $allocator->allocate(8, $jobs);

        $this->assertSame(8, $plan->getAllocation('phpcs_all'));
        $this->assertSame(1, $plan->getMaxParallelJobs());
    }

    // ========================================================================
    // Edge cases targeting escaped mutants
    // ========================================================================

    /** @test */
    function it_clamps_negative_budget_to_1()
    {
        $allocator = new ThreadBudgetAllocator();
        $jobs = [$this->makeJob('a')];

        $plan = $allocator->allocate(-5, $jobs);

        $this->assertSame(1, $plan->getAllocation('a'));
        $this->assertSame(1, $plan->getMaxParallelJobs());
    }

    /** @test */
    function it_clamps_zero_budget_to_1()
    {
        $allocator = new ThreadBudgetAllocator();
        $jobs = [$this->makeJob('a')];

        $plan = $allocator->allocate(0, $jobs);

        $this->assertSame(1, $plan->getAllocation('a'));
        $this->assertSame(1, $plan->getMaxParallelJobs());
    }

    /** @test */
    function all_uncontrollable_jobs_exceed_budget()
    {
        $allocator = new ThreadBudgetAllocator();
        $jobs = [
            $this->makeJob('phpstan', new ThreadCapability('_internal', 4, 1, false)),
            $this->makeJob('psalm', new ThreadCapability('_internal', 6, 1, false)),
        ];

        $plan = $allocator->allocate(5, $jobs);

        // Both are uncontrollable, fixed cost = 10 > budget = 5
        // maxParallel should still be >= 1
        $this->assertSame(4, $plan->getAllocation('phpstan'));
        $this->assertSame(6, $plan->getAllocation('psalm'));
        $this->assertGreaterThanOrEqual(1, $plan->getMaxParallelJobs());
    }

    /** @test */
    function budget_exactly_equals_fixed_costs_gives_threadable_minimum()
    {
        $allocator = new ThreadBudgetAllocator();
        $jobs = [
            $this->makeJob('a'),  // fixed: 1
            $this->makeJob('b'),  // fixed: 1
            $this->makeJob('c', new ThreadCapability('parallel', 8)),
        ];

        // Budget = 2 = fixed cost of a+b → remainingBudget = 0 → threadable gets 1
        $plan = $allocator->allocate(2, $jobs);

        $this->assertSame(1, $plan->getAllocation('a'));
        $this->assertSame(1, $plan->getAllocation('b'));
        $this->assertSame(1, $plan->getAllocation('c'));
    }

    /** @test */
    function threadable_job_respects_minimum_threads()
    {
        $allocator = new ThreadBudgetAllocator();
        $jobs = [
            $this->makeJob('special', new ThreadCapability('parallel', 8, 3)),
        ];

        // Budget = 2, but minimum = 3 → should still get 3 (max(min, threadsPerJob))
        $plan = $allocator->allocate(2, $jobs);

        $this->assertSame(3, $plan->getAllocation('special'));
    }

    /** @test */
    function calculate_max_parallel_with_budget_exceeding_all_costs()
    {
        $allocator = new ThreadBudgetAllocator();
        $jobs = [
            $this->makeJob('a'),  // 1
            $this->makeJob('b'),  // 1
            $this->makeJob('c'),  // 1
        ];

        $plan = $allocator->allocate(100, $jobs);

        // All 3 jobs can run in parallel (costs 1+1+1 <= 100)
        $this->assertSame(3, $plan->getMaxParallelJobs());
    }

    /** @test */
    function single_non_threadable_job_max_parallel_is_1()
    {
        $allocator = new ThreadBudgetAllocator();
        $jobs = [$this->makeJob('phpmd')];

        $plan = $allocator->allocate(8, $jobs);

        $this->assertSame(1, $plan->getMaxParallelJobs());
        $this->assertSame(1, $plan->getAllocation('phpmd'));
    }

    // ========================================================================
    // cores: N explicit override
    // ========================================================================

    /** @test */
    function cores_override_pins_allocation_on_job_without_capability()
    {
        $allocator = new ThreadBudgetAllocator();
        $jobs = [
            $this->makeJob('paratest_like', null, 4),
            $this->makeJob('phpcpd'),
        ];

        $plan = $allocator->allocate(8, $jobs);

        $this->assertSame(4, $plan->getAllocation('paratest_like'));
        $this->assertSame(1, $plan->getAllocation('phpcpd'));
    }

    /** @test */
    function cores_override_takes_over_controllable_capability_and_skips_reparto()
    {
        $allocator = new ThreadBudgetAllocator();
        $jobs = [
            $this->makeJob('phpcs_with_override', new ThreadCapability('parallel', 8), 2),
            $this->makeJob('psalm_without', new ThreadCapability('threads', 4)),
        ];

        // phpcs_with_override: pinned at 2 regardless of capability default (8).
        // Remaining budget = 8 - 2 = 6 for the single threadable psalm → 6.
        $plan = $allocator->allocate(8, $jobs);

        $this->assertSame(2, $plan->getAllocation('phpcs_with_override'));
        $this->assertSame(6, $plan->getAllocation('psalm_without'));
    }

    /** @test */
    function cores_override_respects_budget_in_max_parallel()
    {
        $allocator = new ThreadBudgetAllocator();
        $jobs = [
            $this->makeJob('heavy', null, 4),   // cores: 4
            $this->makeJob('mid', null, 2),     // cores: 2
            $this->makeJob('light'),            // 1
        ];

        $plan = $allocator->allocate(5, $jobs);

        // Sort ascending: 1, 2, 4. 1+2=3 ≤ 5, +4 = 7 > 5. maxParallel = 2.
        $this->assertSame(4, $plan->getAllocation('heavy'));
        $this->assertSame(2, $plan->getAllocation('mid'));
        $this->assertSame(1, $plan->getAllocation('light'));
        $this->assertSame(2, $plan->getMaxParallelJobs());
    }

    /** @test */
    function cores_override_dominates_uncontrollable_capability()
    {
        $allocator = new ThreadBudgetAllocator();
        $jobs = [
            $this->makeJob('phpstan_like', new ThreadCapability('_internal', 8, 1, false), 2),
        ];

        // Without override the job would cost 8 (uncontrollable default).
        // With cores: 2 the allocation is pinned at 2.
        $plan = $allocator->allocate(10, $jobs);

        $this->assertSame(2, $plan->getAllocation('phpstan_like'));
    }
}
