<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowPlan;
use Wtyd\GitHooks\Jobs\CustomJob;
use Wtyd\GitHooks\Output\NullOutputHandler;

class FlowExecutorFailFastTest extends TestCase
{
    /**
     * @test
     * When fail-fast triggers in parallel mode, jobs that were running concurrently
     * should still appear in the results with their (partial) output collected.
     */
    public function parallel_fail_fast_collects_results_from_in_flight_jobs()
    {
        $executor = new FlowExecutor(new NullOutputHandler());

        // Job that fails immediately
        $fastFail = new CustomJob(new JobConfiguration('fast_fail', 'custom', [
            'script' => 'echo "fast output" && exit 1',
        ]));

        // Job that takes long enough to still be running when fast_fail finishes
        $slowJob = new CustomJob(new JobConfiguration('slow_job', 'custom', [
            'script' => 'echo "slow partial output" && sleep 5',
        ]));

        $options = new OptionsConfiguration(true, 2); // fail-fast=true, processes=2
        $plan = new FlowPlan('test', [$fastFail, $slowJob], $options);

        $result = $executor->execute($plan);
        $jobResults = $result->getJobResults();

        $jobNames = array_map(fn($r) => $r->getJobName(), $jobResults);

        $this->assertContains('fast_fail', $jobNames, 'The failed job should be in results');
        $this->assertContains('slow_job', $jobNames, 'The in-flight terminated job should be in results');
        $this->assertCount(2, $jobResults, 'Both jobs should appear in results');
    }

    /**
     * @test
     * The terminated in-flight job should have its partial output captured when
     * the process has had time to flush. We use a small sleep to ensure the echo
     * completes before termination, avoiding timing-dependent failures in CI.
     */
    public function parallel_fail_fast_captures_output_from_terminated_jobs()
    {
        $executor = new FlowExecutor(new NullOutputHandler());

        // This job sleeps briefly to let slow_job's echo flush, then fails
        $fastFail = new CustomJob(new JobConfiguration('fast_fail', 'custom', [
            'script' => 'sleep 0.2 && echo "fail output" && exit 1',
        ]));

        // This job echoes immediately then sleeps — output should be captured before termination
        $slowJob = new CustomJob(new JobConfiguration('slow_job', 'custom', [
            'script' => 'echo "partial data" && sleep 5',
        ]));

        $options = new OptionsConfiguration(true, 2);
        $plan = new FlowPlan('test', [$fastFail, $slowJob], $options);

        $result = $executor->execute($plan);

        $slowResult = null;
        foreach ($result->getJobResults() as $jr) {
            if ($jr->getJobName() === 'slow_job') {
                $slowResult = $jr;
                break;
            }
        }

        $this->assertNotNull($slowResult, 'slow_job should be in results');
        $this->assertStringContainsString('partial data', $slowResult->getOutput());
    }

    /**
     * @test
     * With 3 jobs (processes=2, fail-fast): first fails, second is in-flight, third never starts.
     * All three should appear in results: failed, terminated, and 0 unstarted (skipped not in results).
     * The total count should be 2 (failed + in-flight). The third is skipped (not a JobResult).
     */
    public function parallel_fail_fast_with_queued_and_in_flight_jobs()
    {
        $executor = new FlowExecutor(new NullOutputHandler());

        $fastFail = new CustomJob(new JobConfiguration('fast_fail', 'custom', [
            'script' => 'echo "fail" && exit 1',
        ]));

        $slowJob = new CustomJob(new JobConfiguration('slow_job', 'custom', [
            'script' => 'echo "slow" && sleep 5',
        ]));

        $queuedJob = new CustomJob(new JobConfiguration('queued_job', 'custom', [
            'script' => 'echo "should not run"',
        ]));

        $options = new OptionsConfiguration(true, 2); // Only 2 parallel slots
        $plan = new FlowPlan('test', [$fastFail, $slowJob, $queuedJob], $options);

        $result = $executor->execute($plan);
        $jobResults = $result->getJobResults();

        $jobNames = array_map(fn($r) => $r->getJobName(), $jobResults);

        // fast_fail and slow_job both started (processes=2), queued_job never started
        $this->assertContains('fast_fail', $jobNames);
        $this->assertContains('slow_job', $jobNames);
        // queued_job appears as a skipped entry so structured formats (JSON/JUnit/SARIF)
        // can report the full plan, not just the subset that actually ran.
        $this->assertContains('queued_job', $jobNames);
        $this->assertCount(3, $jobResults);

        $queuedResult = null;
        foreach ($jobResults as $jr) {
            if ($jr->getJobName() === 'queued_job') {
                $queuedResult = $jr;
            }
        }
        $this->assertNotNull($queuedResult);
        $this->assertTrue($queuedResult->isSkipped());
        $this->assertSame('skipped by fail-fast', $queuedResult->getSkipReason());
    }

    /** @test */
    public function sequential_fail_fast_includes_remaining_jobs_as_skipped_in_results()
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
    public function sequential_fail_fast_preserves_type_and_paths_in_skipped_results()
    {
        $executor = new FlowExecutor(new NullOutputHandler());

        $jobs = [
            new CustomJob(new JobConfiguration('fail', 'custom', ['script' => 'exit 1', 'paths' => ['src']])),
            new CustomJob(new JobConfiguration('never', 'custom', ['script' => 'echo never', 'paths' => ['tests']])),
        ];

        $plan = new FlowPlan('test', $jobs, new OptionsConfiguration(true, 1));
        $result = $executor->execute($plan);

        $neverResult = null;
        foreach ($result->getJobResults() as $jr) {
            if ($jr->getJobName() === 'never') {
                $neverResult = $jr;
            }
        }

        $this->assertNotNull($neverResult);
        $this->assertSame('custom', $neverResult->getType());
        $this->assertSame(['tests'], $neverResult->getPaths());
    }

    /** @test */
    public function parallel_fail_fast_queued_job_preserves_type_and_paths()
    {
        $executor = new FlowExecutor(new NullOutputHandler());

        $fastFail = new CustomJob(new JobConfiguration('fast_fail', 'custom', [
            'script' => 'exit 1',
        ]));
        $slowJob = new CustomJob(new JobConfiguration('slow_job', 'custom', [
            'script' => 'sleep 5',
        ]));
        $queuedJob = new CustomJob(new JobConfiguration('queued_job', 'custom', [
            'script' => 'echo queued',
            'paths' => ['src/queued'],
        ]));

        $plan = new FlowPlan('test', [$fastFail, $slowJob, $queuedJob], new OptionsConfiguration(true, 2));
        $result = $executor->execute($plan);

        $queuedResult = null;
        foreach ($result->getJobResults() as $jr) {
            if ($jr->getJobName() === 'queued_job') {
                $queuedResult = $jr;
            }
        }

        $this->assertNotNull($queuedResult);
        $this->assertTrue($queuedResult->isSkipped());
        $this->assertSame('custom', $queuedResult->getType());
        $this->assertSame(['src/queued'], $queuedResult->getPaths());
    }
}
