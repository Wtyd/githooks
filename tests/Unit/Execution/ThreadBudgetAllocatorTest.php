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
    private function makeJob(string $name, ?ThreadCapability $capability = null): JobAbstract
    {
        $config = new JobConfiguration($name, 'phpcs', []);
        $job = Mockery::mock(JobAbstract::class)->makePartial();
        $job->shouldReceive('getName')->andReturn($name);
        $job->shouldReceive('getThreadCapability')->andReturn($capability);
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
}
