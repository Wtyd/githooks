<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use Tests\Utils\TestCase\UnitTestCase;
use Tests\Doubles\FakeProcessPool;
use Tests\Doubles\InjectableFlowExecutor;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowPlan;
use Wtyd\GitHooks\Jobs\CustomJob;
use Wtyd\GitHooks\Output\NullOutputHandler;

/**
 * Verifies the fail-fast contract of FlowExecutor across the two paths
 * (parallel pool / sequential loop). All parallel tests use FakeProcessPool
 * so no real subprocess is spawned — the executor walks its inner loop
 * deterministically and the fake pool enforces a safety cap that would
 * trip if a regression turned the loop into a livelock.
 *
 * After FEAT-3 the fail-fast contract is: jobs in `running` finish
 * naturally (no terminateAll), the queue is drained with `skipped by
 * fail-fast` (or the FEAT-3 needs-aware reason when a dependency graph
 * is in play), and every queued job emits an `onJobSkipped` event so
 * structured formats still report the full plan.
 */
class FlowExecutorFailFastTest extends UnitTestCase
{
    /**
     * @test
     * When fail-fast triggers in parallel mode, jobs that were running at
     * the failure point still appear in the results with their output
     * collected. They finish naturally — fail-fast no longer terminates
     * them.
     */
    public function parallel_fail_fast_collects_results_from_in_flight_jobs(): void
    {
        $fastFail = new CustomJob(new JobConfiguration('fast_fail', 'custom', [
            'script' => 'unused-by-fake',
        ]));
        $slowJob = new CustomJob(new JobConfiguration('slow_job', 'custom', [
            'script' => 'unused-by-fake',
        ]));

        $pool = new FakeProcessPool(2);
        $pool->programResult('fast_fail', 1, 'fast output');
        $pool->programResult('slow_job', 0, 'slow partial output', '', 2);

        $executor = new InjectableFlowExecutor(new NullOutputHandler());
        $executor->injectPool($pool);

        $plan = new FlowPlan('test', [$fastFail, $slowJob], new OptionsConfiguration(true, 2));
        $result = $executor->execute($plan);

        $this->assertNotNull($result->getJobResult('fast_fail'));
        $this->assertNotNull($result->getJobResult('slow_job'));
        $this->assertCount(2, $result->getJobResults());
    }

    /**
     * @test
     * The output of an in-flight job at the failure point is captured. The
     * fake pool keeps slow_job in the running set for two polls so the
     * executor goes through one fail-fast iteration before it finishes
     * naturally.
     */
    public function parallel_fail_fast_captures_output_from_in_flight_jobs(): void
    {
        $fastFail = new CustomJob(new JobConfiguration('fast_fail', 'custom', [
            'script' => 'unused-by-fake',
        ]));
        $slowJob = new CustomJob(new JobConfiguration('slow_job', 'custom', [
            'script' => 'unused-by-fake',
        ]));

        $pool = new FakeProcessPool(2);
        $pool->programResult('fast_fail', 1, 'fail output');
        $pool->programResult('slow_job', 0, 'partial data', '', 3);

        $executor = new InjectableFlowExecutor(new NullOutputHandler());
        $executor->injectPool($pool);

        $plan = new FlowPlan('test', [$fastFail, $slowJob], new OptionsConfiguration(true, 2));
        $result = $executor->execute($plan);

        $slowResult = $result->getJobResult('slow_job');
        $this->assertNotNull($slowResult, 'slow_job should be in results');
        $this->assertStringContainsString('partial data', $slowResult->getOutput());
    }

    /**
     * @test
     * With 3 jobs (processes=2, fail-fast): fast_fail starts and exits 1
     * immediately, slow_job is in-flight, queued_job never starts. All
     * three appear in results: fast_fail failed, slow_job completes
     * naturally with the programmed exit code, queued_job is skipped with
     * `skipped by fail-fast`.
     */
    public function parallel_fail_fast_with_queued_and_in_flight_jobs(): void
    {
        $fastFail = new CustomJob(new JobConfiguration('fast_fail', 'custom', ['script' => 'unused-by-fake']));
        $slowJob = new CustomJob(new JobConfiguration('slow_job', 'custom', ['script' => 'unused-by-fake']));
        $queuedJob = new CustomJob(new JobConfiguration('queued_job', 'custom', ['script' => 'unused-by-fake']));

        $pool = new FakeProcessPool(2);
        $pool->programResult('fast_fail', 1, 'fail');
        $pool->programResult('slow_job', 0, 'slow', '', 2);
        // queued_job is never started, so its programmed result is irrelevant —
        // fail-fast drains it as skipped before its slot is ever filled.

        $executor = new InjectableFlowExecutor(new NullOutputHandler());
        $executor->injectPool($pool);

        $plan = new FlowPlan('test', [$fastFail, $slowJob, $queuedJob], new OptionsConfiguration(true, 2));
        $result = $executor->execute($plan);

        $this->assertNotNull($result->getJobResult('fast_fail'));
        $this->assertNotNull($result->getJobResult('slow_job'));
        $queuedResult = $result->getJobResult('queued_job');
        $this->assertNotNull($queuedResult);
        $this->assertCount(3, $result->getJobResults());

        $this->assertTrue($queuedResult->isSkipped());
        $this->assertSame('skipped by fail-fast', $queuedResult->getSkipReason());
    }

    /**
     * @test
     * Sequential mode (processes=1) is independent of ProcessPool — it runs
     * jobs one by one and never reaches `executeParallel`. The shell calls
     * here are immediate (`exit 1`, `echo`) so the test is fast without
     * needing a fake.
     */
    public function sequential_fail_fast_includes_remaining_jobs_as_skipped_in_results(): void
    {
        $executor = new FlowExecutor(new NullOutputHandler());

        $jobs = [
            new CustomJob(new JobConfiguration('fail', 'custom', ['script' => 'exit 1'])),
            new CustomJob(new JobConfiguration('never', 'custom', ['script' => 'echo never'])),
            new CustomJob(new JobConfiguration('also_never', 'custom', ['script' => 'echo also'])),
        ];

        $plan = new FlowPlan('test', $jobs, new OptionsConfiguration(true, 1));
        $result = $executor->execute($plan);

        $jobNames = array_map(fn($r) => $r->getJobName(), $result->getJobResults());
        $this->assertSame(['fail', 'never', 'also_never'], $jobNames);

        $skippedResults = array_filter($result->getJobResults(), fn($r) => $r->isSkipped());
        $this->assertCount(2, $skippedResults);
        foreach ($skippedResults as $jr) {
            $this->assertSame('skipped by fail-fast', $jr->getSkipReason());
        }
    }

    /** @test */
    public function sequential_fail_fast_preserves_type_and_paths_in_skipped_results(): void
    {
        $executor = new FlowExecutor(new NullOutputHandler());

        $jobs = [
            new CustomJob(new JobConfiguration('fail', 'custom', ['script' => 'exit 1', 'paths' => ['src']])),
            new CustomJob(new JobConfiguration('never', 'custom', ['script' => 'echo never', 'paths' => ['tests']])),
        ];

        $plan = new FlowPlan('test', $jobs, new OptionsConfiguration(true, 1));
        $result = $executor->execute($plan);

        $neverResult = $result->getJobResult('never');
        $this->assertNotNull($neverResult);
        $this->assertSame('custom', $neverResult->getType());
        $this->assertSame(['tests'], $neverResult->getPaths());
    }

    /**
     * @test
     * Parallel-mode queued job preserves its type/paths in the skipped
     * JobResult so structured formatters (JSON, JUnit, SARIF, CodeClimate)
     * can emit the full plan, not just the subset that actually ran.
     */
    public function parallel_fail_fast_queued_job_preserves_type_and_paths(): void
    {
        $fastFail = new CustomJob(new JobConfiguration('fast_fail', 'custom', ['script' => 'unused-by-fake']));
        $slowJob = new CustomJob(new JobConfiguration('slow_job', 'custom', ['script' => 'unused-by-fake']));
        $queuedJob = new CustomJob(new JobConfiguration('queued_job', 'custom', [
            'script' => 'unused-by-fake',
            'paths'  => ['src/queued'],
        ]));

        $pool = new FakeProcessPool(2);
        $pool->programResult('fast_fail', 1, '');
        $pool->programResult('slow_job', 0, '', '', 2);

        $executor = new InjectableFlowExecutor(new NullOutputHandler());
        $executor->injectPool($pool);

        $plan = new FlowPlan('test', [$fastFail, $slowJob, $queuedJob], new OptionsConfiguration(true, 2));
        $result = $executor->execute($plan);

        $queuedResult = $result->getJobResult('queued_job');
        $this->assertNotNull($queuedResult);
        $this->assertTrue($queuedResult->isSkipped());
        $this->assertSame('custom', $queuedResult->getType());
        $this->assertSame(['src/queued'], $queuedResult->getPaths());
    }
}
