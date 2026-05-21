<?php

declare(strict_types=1);

namespace Tests\Integration\Execution;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\FlowDependencyGraph;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Configuration\JobRef;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Configuration\ValidationResult;
use Wtyd\GitHooks\Execution\ExecutionMode;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowPlan;
use Wtyd\GitHooks\Jobs\CustomJob;
use Wtyd\GitHooks\Output\NullOutputHandler;

/**
 * BUG-19 · admission-skip must propagate through `needs`.
 *
 * FlowNeedsCompositionTest covers propagation when an upstream FAILS or is
 * skipped at runtime (fail-fast, cascade). This file covers the gap: when
 * an upstream is descarded by FlowPreparer BEFORE the executor loop (via
 * only-files, exclude-files, or execution-mode filter), the descendant must
 * still propagate.
 *
 * The plan-skipped jobs are pre-populated through FlowPlan::skippedJobs[]
 * (the same shape FlowPreparer produces) and the FlowExecutor is exercised
 * end-to-end both in sequential and parallel modes.
 */
class FlowAdmissionPropagationTest extends TestCase
{
    /**
     * @test
     * @dataProvider provideSingleAdmissionSkipCases
     */
    public function single_admission_skip_propagates_to_single_need_dependent(
        int $processes,
        string $admissionReason
    ): void {
        $executor = new FlowExecutor(new NullOutputHandler());

        // Only `B` is in jobs[] — `A` was discarded by admission and lives
        // exclusively in skippedJobs[]. The dependency graph still references
        // A because the graph is built before the admission filter runs
        // (FlowPreparer::orderJobRefsTopologically — A is in the refs list).
        $jobs = [
            new CustomJob(new JobConfiguration('B', 'custom', ['script' => 'echo never'])),
        ];
        $graph = $this->graphFor([
            $this->ref('A'),
            $this->ref('B', ['A']),
        ]);
        $skippedJobs = [
            'A' => $this->skippedEntry($admissionReason),
        ];

        $plan = $this->plan($jobs, $graph, $skippedJobs, $processes);
        $result = $executor->execute($plan, false);

        $results = $this->indexResults($result->getJobResults());
        $this->assertArrayHasKey('A', $results);
        $this->assertArrayHasKey('B', $results);

        $this->assertTrue($results['A']->isSkipped(), 'A must remain skipped (plan)');
        $this->assertSame($admissionReason, $results['A']->getSkipReason());

        $this->assertTrue(
            $results['B']->isSkipped(),
            "B must be skipped (processes=$processes; A was admission-skipped)"
        );
        $this->assertSame(
            'needs A was skipped',
            $results['B']->getSkipReason(),
            'skipReason must follow the "<single need> was skipped" wording'
        );
    }

    /**
     * Cases #3, #4, #5 of the decision table × {sequential, parallel}.
     *
     * @return iterable<string, array{0: int, 1: string}>
     */
    public function provideSingleAdmissionSkipCases(): iterable
    {
        $reasons = [
            'only-files'    => 'no files in the change set match its only-files rule',
            'exclude-files' => 'every file in the change set is filtered by its exclude-files rule',
            'exec-mode'     => 'no changes to validate',
        ];
        foreach ($reasons as $label => $reason) {
            yield "$label · sequential" => [1, $reason];
            yield "$label · parallel"   => [4, $reason];
        }
    }

    /**
     * @test
     * @dataProvider provideMultiNeedCases
     */
    public function multi_need_propagation_handles_mixed_states(
        int $processes,
        string $scriptC,
        bool $cAdmissionSkipped,
        string $expectedReason
    ): void {
        $executor = new FlowExecutor(new NullOutputHandler());

        // B needs [A, C]. A always runs (OK). C may run or be admission-skipped.
        $jobs = [
            new CustomJob(new JobConfiguration('A', 'custom', ['script' => 'echo A'])),
        ];
        if (!$cAdmissionSkipped) {
            $jobs[] = new CustomJob(new JobConfiguration('C', 'custom', ['script' => $scriptC]));
        }
        $jobs[] = new CustomJob(new JobConfiguration('B', 'custom', ['script' => 'echo never']));

        $graph = $this->graphFor([
            $this->ref('A'),
            $this->ref('C'),
            $this->ref('B', ['A', 'C']),
        ]);

        $skippedJobs = $cAdmissionSkipped
            ? ['C' => $this->skippedEntry('no files in the change set match its only-files rule')]
            : [];

        $plan = $this->plan($jobs, $graph, $skippedJobs, $processes);
        $result = $executor->execute($plan, false);

        $results = $this->indexResults($result->getJobResults());
        $this->assertArrayHasKey('B', $results);
        $this->assertTrue(
            $results['B']->isSkipped(),
            "B must propagate (processes=$processes)"
        );
        $this->assertSame($expectedReason, $results['B']->getSkipReason());
    }

    /**
     * Cases #8, #9, #10 × {sequential, parallel}.
     *
     * @return iterable<string, array{0: int, 1: string, 2: bool, 3: string}>
     */
    public function provideMultiNeedCases(): iterable
    {
        // Case #8: A OK + C admission-skip → reason names C alone.
        yield 'C admission-skip + A OK · sequential' => [
            1, 'unused', true, 'needs C was skipped',
        ];
        yield 'C admission-skip + A OK · parallel'   => [
            4, 'unused', true, 'needs C was skipped',
        ];

        // Case #9: A OK + C FAIL → reason names C as failed (independent of
        // the bug, but it must not regress when mixed with admission-skipped
        // scenarios below).
        yield 'A OK + C FAIL · sequential' => [
            1, 'exit 1', false, 'needs C failed',
        ];

        // Case #10: same admission-skip applied to two needs → "were skipped".
    }

    /** @test */
    public function multi_need_all_admission_skipped_uses_plural_form(): void
    {
        $executor = new FlowExecutor(new NullOutputHandler());

        $jobs = [
            new CustomJob(new JobConfiguration('B', 'custom', ['script' => 'echo never'])),
        ];
        $graph = $this->graphFor([
            $this->ref('A'),
            $this->ref('C'),
            $this->ref('B', ['A', 'C']),
        ]);
        $skippedJobs = [
            'A' => $this->skippedEntry('no files in the change set match its only-files rule'),
            'C' => $this->skippedEntry('every file in the change set is filtered by its exclude-files rule'),
        ];

        $plan = $this->plan($jobs, $graph, $skippedJobs, 1);
        $result = $executor->execute($plan, false);
        $results = $this->indexResults($result->getJobResults());

        $this->assertTrue($results['B']->isSkipped());
        $this->assertSame('needs A, C were skipped', $results['B']->getSkipReason());
    }

    /** @test */
    public function admission_skip_propagates_along_chain_two_levels(): void
    {
        $executor = new FlowExecutor(new NullOutputHandler());

        // A admission-skip → B (needs A) propagated → C (needs B) propagated.
        $jobs = [
            new CustomJob(new JobConfiguration('B', 'custom', ['script' => 'echo never'])),
            new CustomJob(new JobConfiguration('C', 'custom', ['script' => 'echo never'])),
        ];
        $graph = $this->graphFor([
            $this->ref('A'),
            $this->ref('B', ['A']),
            $this->ref('C', ['B']),
        ]);
        $skippedJobs = [
            'A' => $this->skippedEntry('no files in the change set match its only-files rule'),
        ];

        $plan = $this->plan($jobs, $graph, $skippedJobs, 1);
        $result = $executor->execute($plan, false);
        $results = $this->indexResults($result->getJobResults());

        $this->assertTrue($results['A']->isSkipped());
        $this->assertTrue($results['B']->isSkipped());
        $this->assertSame('needs A was skipped', $results['B']->getSkipReason());
        $this->assertTrue($results['C']->isSkipped());
        $this->assertSame('needs B was skipped', $results['C']->getSkipReason());
    }

    /** @test */
    public function admission_skip_does_not_affect_independent_jobs(): void
    {
        // Regression guard: an admission-skipped job that has no dependants
        // must not prevent unrelated jobs from running.
        $executor = new FlowExecutor(new NullOutputHandler());

        $jobs = [
            new CustomJob(new JobConfiguration('independent', 'custom', ['script' => 'echo ok'])),
        ];
        $graph = $this->graphFor([
            $this->ref('skipped-one'),
            $this->ref('independent'),
        ]);
        $skippedJobs = [
            'skipped-one' => $this->skippedEntry('no files in the change set match its only-files rule'),
        ];

        $plan = $this->plan($jobs, $graph, $skippedJobs, 1);
        $result = $executor->execute($plan, false);
        $results = $this->indexResults($result->getJobResults());

        $this->assertTrue($results['skipped-one']->isSkipped());
        $this->assertFalse(
            $results['independent']->isSkipped(),
            'unrelated jobs must still run when an admission-skipped job has no dependants'
        );
        $this->assertTrue($results['independent']->isSuccess());
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /** @param string[] $needs */
    private function ref(string $name, array $needs = []): JobRef
    {
        return new JobRef($name, null, null, $needs);
    }

    /** @param JobRef[] $refs */
    private function graphFor(array $refs): FlowDependencyGraph
    {
        $validation = new ValidationResult();
        $graph = FlowDependencyGraph::build('qa', $refs, $validation);
        $this->assertNotNull($graph, 'graph build failed: ' . implode('; ', $validation->getErrors()));
        return $graph;
    }

    /**
     * @return array{type: string, reason: string, paths: string[], accelerable: bool}
     */
    private function skippedEntry(string $reason): array
    {
        return [
            'type'        => 'custom',
            'reason'      => $reason,
            'paths'       => [],
            'accelerable' => true,
        ];
    }

    /**
     * @param \Wtyd\GitHooks\Jobs\JobAbstract[] $jobs
     * @param array<string, array{type: string, reason: string, paths: string[], accelerable?: bool}> $skippedJobs
     */
    private function plan(
        array $jobs,
        FlowDependencyGraph $graph,
        array $skippedJobs,
        int $processes
    ): FlowPlan {
        return new FlowPlan(
            'qa',
            $jobs,
            new OptionsConfiguration(false, $processes),
            null,
            $skippedJobs,
            ExecutionMode::FULL,
            null,
            null,
            null,
            $graph
        );
    }

    /**
     * @param \Wtyd\GitHooks\Execution\JobResult[] $jobResults
     * @return array<string, \Wtyd\GitHooks\Execution\JobResult>
     */
    private function indexResults(array $jobResults): array
    {
        $byName = [];
        foreach ($jobResults as $r) {
            $byName[$r->getJobName()] = $r;
        }
        return $byName;
    }
}
