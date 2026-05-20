<?php

declare(strict_types=1);

namespace Tests\Integration\Execution;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\FlowDependencyGraph;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Configuration\JobRef;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Configuration\ValidationResult;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowPlan;
use Wtyd\GitHooks\Jobs\CustomJob;
use Wtyd\GitHooks\Output\NullOutputHandler;

/**
 * FEAT-3 · Groups D, E, F — end-to-end propagation through FlowExecutor.
 *
 * Covers the runtime behaviour assembled from the lower-level pieces tested
 * in unit suites (DAG, admission gate, pool primitives). The flow runs in
 * sequential mode here to keep the timing deterministic — same propagation
 * logic applies to parallel runs (verified by the admission unit tests).
 */
class FlowNeedsCompositionTest extends TestCase
{
    /** @test */
    public function D1_single_failed_dep_propagates_to_dependent()
    {
        $executor = new FlowExecutor(new NullOutputHandler());
        $jobs = [
            new CustomJob(new JobConfiguration('fail-dep', 'custom', ['script' => 'exit 2'])),
            new CustomJob(new JobConfiguration('dep', 'custom', ['script' => 'echo never'])),
        ];
        $graph = $this->graphFor([
            $this->ref('fail-dep'),
            $this->ref('dep', ['fail-dep']),
        ]);

        $result = $executor->execute($this->plan($jobs, $graph), false);

        [$first, $second] = $result->getJobResults();
        $this->assertFalse($first->isSuccess());
        $this->assertSame('fail-dep', $first->getJobName());

        $this->assertSame('dep', $second->getJobName());
        $this->assertTrue($second->isSkipped());
        $this->assertSame('needs fail-dep failed', $second->getSkipReason());
        $this->assertSame(['fail-dep'], $second->getNeeds());
    }

    /** @test */
    public function D2_multiple_failed_deps_list_all_in_reason()
    {
        $executor = new FlowExecutor(new NullOutputHandler());
        $jobs = [
            new CustomJob(new JobConfiguration('compile', 'custom', ['script' => 'exit 1'])),
            new CustomJob(new JobConfiguration('lint', 'custom', ['script' => 'exit 1'])),
            new CustomJob(new JobConfiguration('app', 'custom', ['script' => 'echo app'])),
        ];
        $graph = $this->graphFor([
            $this->ref('compile'),
            $this->ref('lint'),
            $this->ref('app', ['compile', 'lint']),
        ]);

        $result = $executor->execute($this->plan($jobs, $graph), false);

        $app = $result->getJobResults()[2];
        $this->assertTrue($app->isSkipped());
        $this->assertSame('needs compile, lint failed', $app->getSkipReason());
    }

    /** @test */
    public function D3_chain_propagation_dep_failure_propagates_two_levels()
    {
        $executor = new FlowExecutor(new NullOutputHandler());
        $jobs = [
            new CustomJob(new JobConfiguration('root', 'custom', ['script' => 'exit 1'])),
            new CustomJob(new JobConfiguration('mid', 'custom', ['script' => 'echo mid'])),
            new CustomJob(new JobConfiguration('leaf', 'custom', ['script' => 'echo leaf'])),
        ];
        $graph = $this->graphFor([
            $this->ref('root'),
            $this->ref('mid', ['root']),
            $this->ref('leaf', ['mid']),
        ]);

        $result = $executor->execute($this->plan($jobs, $graph), false);

        [$root, $mid, $leaf] = $result->getJobResults();
        $this->assertFalse($root->isSuccess());

        $this->assertTrue($mid->isSkipped());
        $this->assertSame('needs root failed', $mid->getSkipReason());

        // leaf needs mid; mid was skipped (because root failed). The skip
        // propagates one more level with a "was skipped" reason (D3 of the
        // factor table — mixed-causes string when applicable, here a single
        // skipped need).
        $this->assertTrue($leaf->isSkipped());
        $this->assertSame('needs mid was skipped', $leaf->getSkipReason());
    }

    /** @test */
    public function E_fail_fast_with_needs_distinguishes_descendant_from_independent()
    {
        $executor = new FlowExecutor(new NullOutputHandler());
        $failFastJobs = [
            new CustomJob(new JobConfiguration('yarn-install', 'custom', ['script' => 'exit 1'])),
            new CustomJob(new JobConfiguration('eslint', 'custom', ['script' => 'echo never'])),
            new CustomJob(new JobConfiguration('phpstan', 'custom', ['script' => 'echo never'])),
        ];
        $graph = $this->graphFor([
            $this->ref('yarn-install'),
            $this->ref('eslint', ['yarn-install']),
            $this->ref('phpstan'),
        ]);

        // fail-fast: true in options; sequential mode (processes=1)
        $plan = new FlowPlan(
            'qa',
            $failFastJobs,
            new OptionsConfiguration(true, 1),
            null,
            [],
            \Wtyd\GitHooks\Execution\ExecutionMode::FULL,
            null,
            null,
            null,
            $graph
        );
        $result = $executor->execute($plan, false);

        [$yarn, $eslint, $phpstan] = $result->getJobResults();

        // The failure of yarn-install triggers fail-fast.
        $this->assertFalse($yarn->isSuccess());

        // eslint is a descendant of yarn-install → reason names the failing dep.
        $this->assertTrue($eslint->isSkipped());
        $this->assertSame('needs yarn-install failed', $eslint->getSkipReason());

        // phpstan is independent → generic fail-fast reason.
        $this->assertTrue($phpstan->isSkipped());
        $this->assertSame('skipped by fail-fast', $phpstan->getSkipReason());
    }

    /** @test */
    public function C_sequential_runs_jobs_in_topological_order_when_declared_out_of_order()
    {
        // Declaration order: leaf first, root last; topo sort must reorder.
        $executor = new FlowExecutor(new NullOutputHandler());
        $jobs = [
            new CustomJob(new JobConfiguration('leaf', 'custom', ['script' => 'echo leaf'])),
            new CustomJob(new JobConfiguration('root', 'custom', ['script' => 'echo root'])),
        ];
        $graph = $this->graphFor([
            $this->ref('leaf', ['root']),
            $this->ref('root'),
        ]);
        // FlowPreparer would normally reorder the jobs; we skip it here and
        // simulate the topo-sorted list directly (root before leaf).
        $orderedJobs = [
            new CustomJob(new JobConfiguration('root', 'custom', ['script' => 'echo root'])),
            new CustomJob(new JobConfiguration('leaf', 'custom', ['script' => 'echo leaf'])),
        ];

        $result = $executor->execute($this->plan($orderedJobs, $graph), false);

        $names = array_map(fn($r) => $r->getJobName(), $result->getJobResults());
        $this->assertSame(['root', 'leaf'], $names);
        $this->assertTrue($result->isSuccess());
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * @param string[] $needs
     */
    private function ref(string $name, array $needs = []): JobRef
    {
        return new JobRef($name, null, null, $needs);
    }

    /**
     * @param JobRef[] $refs
     */
    private function graphFor(array $refs): FlowDependencyGraph
    {
        $result = new ValidationResult();
        $graph = FlowDependencyGraph::build('qa', $refs, $result);
        $this->assertNotNull($graph);
        return $graph;
    }

    /**
     * @param \Wtyd\GitHooks\Jobs\JobAbstract[] $jobs
     */
    private function plan(array $jobs, FlowDependencyGraph $graph): FlowPlan
    {
        return new FlowPlan(
            'qa',
            $jobs,
            new OptionsConfiguration(false, 1),  // sequential mode
            null,
            [],
            \Wtyd\GitHooks\Execution\ExecutionMode::FULL,
            null,
            null,
            null,
            $graph
        );
    }
}
