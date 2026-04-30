<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use LogicException;
use Mockery;
use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Execution\ThreadBudgetAllocator;
use Wtyd\GitHooks\Execution\ThreadBudgetPlan;
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

        // phpstan default 4 ≤ budget 5 → unchanged.
        // psalm default 6 > budget 5 → clamped to 5 so the pool can ever admit it
        // (without the clamp, FifoAdmission would reject psalm forever and the
        // flow loop would spin without progress).
        $this->assertSame(4, $plan->getAllocation('phpstan'));
        $this->assertSame(5, $plan->getAllocation('psalm'));
        $this->assertGreaterThanOrEqual(1, $plan->getMaxParallelJobs());
    }

    /** @test */
    function uncontrollable_default_clamped_when_exceeds_budget()
    {
        $allocator = new ThreadBudgetAllocator();
        $jobs = [
            $this->makeJob('parallel_lint', new ThreadCapability('jobs', 1)),
            $this->makeJob('phpcs_src', new ThreadCapability('parallel', 1)),
            $this->makeJob('phpstan_src', new ThreadCapability('_internal', 4, 1, false)),
        ];

        $plan = $allocator->allocate(2, $jobs);

        // Without the clamp phpstan_src=4 with budget=2 leaves FifoAdmission
        // permanently rejecting the queue head (cores 4 > free max 2) and
        // FlowExecutor spins without progress.
        $this->assertSame(2, $plan->getAllocation('phpstan_src'));
        $this->assertGreaterThanOrEqual(1, $plan->getMaxParallelJobs());
    }

    /** @test */
    function explicit_cores_override_clamped_to_budget()
    {
        $allocator = new ThreadBudgetAllocator();
        $jobs = [
            $this->makeJob(
                'phpstan_src',
                new ThreadCapability('_internal', 1, 1, true),
                8
            ),
        ];

        $plan = $allocator->allocate(4, $jobs);

        // cores: 8 declared but budget = 4 → clamp prevents FifoAdmission
        // from rejecting the only job indefinitely.
        $this->assertSame(4, $plan->getAllocation('phpstan_src'));
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

        // Budget = 4, minimum = 3, threadsPerJob = floor(4/1) = 4 → max(3,4)=4. Fits.
        $plan = $allocator->allocate(4, $jobs);

        $this->assertSame(4, $plan->getAllocation('special'));
    }

    /** @test */
    function threadable_minimum_clamped_when_exceeds_budget()
    {
        $allocator = new ThreadBudgetAllocator();
        $jobs = [
            $this->makeJob('special', new ThreadCapability('parallel', 8, 3)),
        ];

        // Budget = 2, minimum = 3 → without clamp, allocation = 3. FifoAdmission
        // would reject 3 cores against a 2-core budget forever, deadlocking the
        // executor. The clamp caps the allocation at the budget.
        $plan = $allocator->allocate(2, $jobs);

        $this->assertSame(2, $plan->getAllocation('special'));
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

    /**
     * @test
     * Mata el mutante Assignment en línea 37: `$fixedCost += $override` →
     * `$fixedCost = $override`. Con 2 jobs `cores: N`, el real acumula
     * (3+3=6) y el mutado solo conserva el último (3). La diferencia se
     * propaga al threadable: 12-6=6 vs 12-3=9.
     */
    function multiple_cores_override_jobs_accumulate_in_fixed_cost()
    {
        $allocator = new ThreadBudgetAllocator();
        $jobs = [
            $this->makeJob('first_pinned', null, 3),
            $this->makeJob('second_pinned', null, 3),
            $this->makeJob('threadable', new ThreadCapability('parallel', 8)),
        ];

        $plan = $allocator->allocate(12, $jobs);

        $this->assertSame(3, $plan->getAllocation('first_pinned'));
        $this->assertSame(3, $plan->getAllocation('second_pinned'));
        $this->assertSame(6, $plan->getAllocation('threadable'));
    }

    /**
     * @test
     * Mata el mutante Assignment en línea 49: `$fixedCost +=
     * $capability->getDefaultThreads()` → `=`. Con 2 jobs uncontrollable,
     * el real acumula (3+3=6); el mutado se queda con el último (3).
     */
    function multiple_uncontrollable_jobs_accumulate_in_fixed_cost()
    {
        $allocator = new ThreadBudgetAllocator();
        $jobs = [
            $this->makeJob('phpstan_a', new ThreadCapability('_internal', 3, 1, false)),
            $this->makeJob('phpstan_b', new ThreadCapability('_internal', 3, 1, false)),
            $this->makeJob('phpcs', new ThreadCapability('parallel', 8)),
        ];

        $plan = $allocator->allocate(12, $jobs);

        $this->assertSame(3, $plan->getAllocation('phpstan_a'));
        $this->assertSame(3, $plan->getAllocation('phpstan_b'));
        $this->assertSame(6, $plan->getAllocation('phpcs'));
    }

    // ========================================================================
    // Family invariant: no per-job allocation may ever exceed the budget.
    // FifoAdmission rejects forever any job with cores > free_max, which in the
    // limit case (cores > budget) means the queue head can never be admitted
    // and FlowExecutor deadlocks. The ThreadBudgetPlan constructor already
    // throws LogicException on violations; these tests cover every code path
    // where an oversized allocation could leak (override, uncontrollable
    // default, threadable minimum, and combinations).
    // ========================================================================

    /**
     * @test
     * @dataProvider oversizedAllocationFamily
     * @param array<int, array{0:string, 1:?ThreadCapability, 2:?int}> $jobSpecs
     */
    function no_per_job_allocation_ever_exceeds_budget(int $budget, array $jobSpecs)
    {
        $jobs = [];
        foreach ($jobSpecs as [$name, $capability, $coresOverride]) {
            $jobs[] = $this->makeJob($name, $capability, $coresOverride);
        }

        $plan = (new ThreadBudgetAllocator())->allocate($budget, $jobs);

        // Invariant guaranteed by ThreadBudgetPlan; assert it explicitly so a
        // failure reports the offending job instead of just throwing.
        foreach ($plan->getAllocations() as $name => $cores) {
            $this->assertLessThanOrEqual(
                $budget,
                $cores,
                "Job '$name' allocated $cores cores against budget $budget — FifoAdmission would deadlock"
            );
            $this->assertGreaterThanOrEqual(1, $cores, "Job '$name' allocated $cores cores (must be ≥ 1)");
        }
        $this->assertGreaterThanOrEqual(1, $plan->getMaxParallelJobs());
    }

    /** @return iterable<string, array{0:int, 1:array<int, array{0:string, 1:?ThreadCapability, 2:?int}>}> */
    public function oversizedAllocationFamily(): iterable
    {
        yield 'cores override > budget, single job' => [
            2,
            [['only', null, 8]],
        ];

        yield 'cores override > budget, multiple jobs' => [
            2,
            [['a', null, 4], ['b', null, 6]],
        ];

        yield 'uncontrollable default > budget, single job' => [
            2,
            [['phpstan', new ThreadCapability('_internal', 8, 1, false), null]],
        ];

        yield 'uncontrollable default > budget, multiple jobs' => [
            2,
            [
                ['phpstan', new ThreadCapability('_internal', 8, 1, false), null],
                ['psalm',   new ThreadCapability('_internal', 6, 1, false), null],
            ],
        ];

        yield 'threadable minimum > budget' => [
            2,
            [['special', new ThreadCapability('parallel', 8, 5), null]],
        ];

        yield 'budget = 1 with oversized override' => [
            1,
            [['only', null, 16]],
        ];

        yield 'budget = 1 with oversized uncontrollable default' => [
            1,
            [['phpstan', new ThreadCapability('_internal', 4, 1, false), null]],
        ];

        yield 'budget = 1 with oversized threadable minimum' => [
            1,
            [['special', new ThreadCapability('parallel', 8, 4), null]],
        ];

        yield 'mixed: override + uncontrollable + threadable, all > budget' => [
            2,
            [
                ['override',       null,                                              5],
                ['uncontrollable', new ThreadCapability('_internal', 6, 1, false),    null],
                ['threadable',    new ThreadCapability('parallel', 8, 4),             null],
            ],
        ];

        yield 'cores override exactly equals budget' => [
            4,
            [['only', null, 4]],
        ];

        yield 'sanity: override well below budget is not clamped' => [
            16,
            [['only', null, 2]],
        ];
    }

    /**
     * @test
     * Direct safety net — if any future producer skips the clamp, the plan
     * constructor itself must refuse to build. This pins the contract so a
     * regression is reported with a precise diagnostic instead of as a
     * 100%-CPU deadlock several layers below.
     */
    function plan_constructor_rejects_oversized_allocation()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/invariant violated.*allocated 4 cores.*budget is 2/');

        new ThreadBudgetPlan(2, 1, ['offending_job' => 4], []);
    }
}
