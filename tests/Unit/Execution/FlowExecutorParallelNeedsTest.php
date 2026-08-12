<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use Tests\Doubles\FakeProcessPool;
use Tests\Doubles\InjectableFlowExecutor;
use Tests\Doubles\OutputHandlerSpy;
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
     * D2 (FEAT-3): a dependent drained because its need FAILED must be
     * announced to the output handler with the propagated reason — not the
     * generic fallback. Kills the mutants at FlowExecutor:786 (Coalesce
     * hardcoding 'needs were not satisfied') and :787 (onJobSkipped removal).
     *
     * @test
     */
    public function drained_dependent_skip_is_announced_with_the_propagated_reason(): void
    {
        $root = new CustomJob(new JobConfiguration('root', 'custom', ['script' => 'unused-by-fake']));
        $dependent = new CustomJob(new JobConfiguration('dependent', 'custom', ['script' => 'unused-by-fake']));

        $pool = new FakeProcessPool(2, new FifoAdmission());
        $pool->programResult('root', 1, 'root failed');

        $spy = new OutputHandlerSpy();
        $executor = new InjectableFlowExecutor($spy);
        $executor->injectPool($pool);

        $graph = $this->graphFor([$this->ref('root'), $this->ref('dependent', ['root'])]);
        $executor->execute($this->parallelPlan([$root, $dependent], $graph));

        $this->assertSame(
            [['job' => 'dependent', 'reason' => 'needs root failed']],
            $spy->skippedJobs,
            'the drained dependent must surface exactly once, with the propagated (not generic) reason'
        );
    }

    /**
     * FEAT-3: a queued dependent waiting for its need is announced ONCE while
     * the blocker set stays the same (re-announcing every loop iteration is
     * spam; never announcing hides the wait). Kills the mutants at
     * FlowExecutor:795 (NotIdentical inverting the dedup) and :796
     * (onJobWaiting removal).
     *
     * @test
     */
    public function waiting_dependent_is_announced_once_while_blockers_are_unchanged(): void
    {
        $root = new CustomJob(new JobConfiguration('root', 'custom', ['script' => 'unused-by-fake']));
        $dependent = new CustomJob(new JobConfiguration('dependent', 'custom', ['script' => 'unused-by-fake']));

        // root stays in-flight for 2 polls → the executor loops ≥2 times with
        // dependent queued and the same blocker set.
        $pool = new FakeProcessPool(2, new FifoAdmission());
        $pool->programResult('root', 0, 'root ok', '', 2);
        $pool->programResult('dependent', 0, 'dependent ok');

        $spy = new OutputHandlerSpy();
        $executor = new InjectableFlowExecutor($spy);
        $executor->injectPool($pool);

        $graph = $this->graphFor([$this->ref('root'), $this->ref('dependent', ['root'])]);
        $executor->execute($this->parallelPlan([$root, $dependent], $graph));

        $this->assertSame(
            [['job' => 'dependent', 'waitingFor' => ['root']]],
            $spy->waitingJobs,
            'exactly one waiting announcement per unchanged blocker set'
        );
    }

    /**
     * The parallel loop must announce every started job to the handler.
     * Kills the mutant at FlowExecutor:807 (onJobStart removal).
     *
     * @test
     */
    public function started_jobs_are_announced_in_execution_order(): void
    {
        $root = new CustomJob(new JobConfiguration('root', 'custom', ['script' => 'unused-by-fake']));
        $dependent = new CustomJob(new JobConfiguration('dependent', 'custom', ['script' => 'unused-by-fake']));

        $pool = new FakeProcessPool(2, new FifoAdmission());
        $pool->programResult('root', 0, 'root ok');
        $pool->programResult('dependent', 0, 'dependent ok');

        $spy = new OutputHandlerSpy();
        $executor = new InjectableFlowExecutor($spy);
        $executor->injectPool($pool);

        $graph = $this->graphFor([$this->ref('root'), $this->ref('dependent', ['root'])]);
        $executor->execute($this->parallelPlan([$root, $dependent], $graph));

        $this->assertSame(['root', 'dependent'], $spy->startedJobs);
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
