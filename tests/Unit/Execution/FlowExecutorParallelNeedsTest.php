<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use Tests\Doubles\FakeProcessPool;
use Tests\Doubles\InjectableFlowExecutor;
use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Configuration\FlowDependencyGraph;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Configuration\JobRef;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Configuration\ValidationResult;
use Wtyd\GitHooks\Execution\Admission\FifoAdmission;
use Wtyd\GitHooks\Execution\ExecutionMode;
use Wtyd\GitHooks\Execution\FlowPlan;
use Wtyd\GitHooks\Jobs\CustomJob;
use Wtyd\GitHooks\Output\NullOutputHandler;

/**
 * FEAT-3 · needs in PARALLEL mode — happy path.
 *
 * Existing needs suites (FlowNeedsCompositionTest, FlowAdmissionPropagationTest)
 * cover failure/skip propagation and run sequentially. The happy path in
 * parallel mode — a dependent that runs because its need SUCCEEDED — was not
 * directly exercised against a real pool.
 *
 * This is the factor-table row "need completed (success) → dependent executes"
 * (see factors.md §2). It kills the mutant at FlowExecutor:784 (FalseValue on
 * the `skipped` flag of `notifyResult`): if a completed job were notified with
 * `skipped = true`, its dependents would never see it in `completedJobs` and
 * would be drained as skipped instead of running.
 */
class FlowExecutorParallelNeedsTest extends UnitTestCase
{
    /** @test */
    public function dependent_runs_when_its_need_succeeds_in_parallel(): void
    {
        $root = new CustomJob(new JobConfiguration('root', 'custom', ['script' => 'unused-by-fake']));
        $dependent = new CustomJob(new JobConfiguration('dependent', 'custom', ['script' => 'unused-by-fake']));

        // FifoAdmission so fillPool honours the needs gate (the null-strategy
        // FIFO path ignores readiness). Both succeed on the first poll.
        $pool = new FakeProcessPool(2, new FifoAdmission());
        $pool->programResult('root', 0, 'root ok');
        $pool->programResult('dependent', 0, 'dependent ok');

        $executor = new InjectableFlowExecutor(new NullOutputHandler());
        $executor->injectPool($pool);

        $graph = $this->graphFor([$this->ref('root'), $this->ref('dependent', ['root'])]);
        $result = $executor->execute($this->parallelPlan([$root, $dependent], $graph));

        $dependentResult = $result->getJobResult('dependent');
        $this->assertNotNull($dependentResult);
        $this->assertFalse($dependentResult->isSkipped(), 'dependent must run, not be drained as skipped');
        $this->assertTrue($dependentResult->isSuccess());
        $this->assertNull($dependentResult->getSkipReason());
    }

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
    private function parallelPlan(array $jobs, FlowDependencyGraph $graph): FlowPlan
    {
        return new FlowPlan(
            'qa',
            $jobs,
            new OptionsConfiguration(false, 2), // parallel: processes=2
            null,
            [],
            ExecutionMode::FULL,
            null,
            null,
            null,
            $graph
        );
    }
}
