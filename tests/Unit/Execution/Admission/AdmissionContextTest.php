<?php

declare(strict_types=1);

namespace Tests\Unit\Execution\Admission;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Execution\Admission\AdmissionContext;
use Wtyd\GitHooks\Jobs\JobAbstract;
use Wtyd\GitHooks\Jobs\PhpcsJob;
use Wtyd\GitHooks\Configuration\JobConfiguration;

class AdmissionContextTest extends TestCase
{
    /** @test */
    public function fits_passes_when_cores_available_and_no_memory_budget(): void
    {
        $job = $this->buildJob('phpstan');
        $ctx = new AdmissionContext(
            4,
            null,
            ['phpstan' => 2],
            ['phpstan' => 2000]
        );

        $this->assertTrue($ctx->fits($job));
    }

    /** @test */
    public function fits_fails_when_cores_exceed_free_pool(): void
    {
        $job = $this->buildJob('phpunit');
        $ctx = new AdmissionContext(
            1,
            null,
            ['phpunit' => 4],
            ['phpunit' => null]
        );

        $this->assertFalse($ctx->fits($job));
    }

    /** @test */
    public function fits_passes_in_2d_when_both_axes_have_room(): void
    {
        $job = $this->buildJob('phpstan');
        $ctx = new AdmissionContext(
            6,
            2500,
            ['phpstan' => 2],
            ['phpstan' => 2000]
        );

        $this->assertTrue($ctx->fits($job));
    }

    /** @test */
    public function fits_fails_in_2d_when_memory_runs_out(): void
    {
        $job = $this->buildJob('phpstan');
        $ctx = new AdmissionContext(
            10,
            500,
            ['phpstan' => 2],
            ['phpstan' => 2000]
        );

        $this->assertFalse($ctx->fits($job));
    }

    /** @test */
    public function fits_fails_in_2d_when_cores_run_out_even_if_memory_fits(): void
    {
        $job = $this->buildJob('phpstan');
        $ctx = new AdmissionContext(
            1,
            5000,
            ['phpstan' => 4],
            ['phpstan' => 2000]
        );

        $this->assertFalse($ctx->fits($job));
    }

    /** @test */
    public function unknown_job_defaults_to_one_core_and_zero_memory(): void
    {
        $job = $this->buildJob('phpstan');
        $ctx = new AdmissionContext(1, 100, [], []);

        $this->assertTrue($ctx->fits($job));
    }

    /** @test */
    public function in_1d_mode_memory_reservation_is_ignored_even_if_declared(): void
    {
        $job = $this->buildJob('phpstan');
        $ctx = new AdmissionContext(
            10,
            null,
            ['phpstan' => 2],
            ['phpstan' => 999999]
        );

        $this->assertTrue($ctx->fits($job));
    }

    /** @test */
    public function isJobReady_true_when_no_needs_declared(): void
    {
        $job = $this->buildJob('phpstan');
        $ctx = $this->contextWithNeeds([], [], [], []);

        $this->assertTrue($ctx->isJobReady($job));
    }

    /** @test */
    public function isJobReady_true_when_all_needs_completed(): void
    {
        $job = $this->buildJob('eslint');
        $ctx = $this->contextWithNeeds(
            ['eslint' => ['yarn-install']],
            ['yarn-install'],
            [],
            []
        );

        $this->assertTrue($ctx->isJobReady($job));
    }

    /** @test */
    public function isJobReady_false_when_some_needs_not_completed(): void
    {
        $job = $this->buildJob('eslint');
        $ctx = $this->contextWithNeeds(
            ['eslint' => ['yarn-install', 'install-deps']],
            ['yarn-install'],  // install-deps still pending
            [],
            []
        );

        $this->assertFalse($ctx->isJobReady($job));
    }

    /** @test */
    public function isJobReady_false_when_a_need_failed(): void
    {
        $job = $this->buildJob('eslint');
        $ctx = $this->contextWithNeeds(
            ['eslint' => ['yarn-install']],
            [],
            ['yarn-install'],
            []
        );

        $this->assertFalse($ctx->isJobReady($job));
    }

    /** @test */
    public function isJobReady_false_when_a_need_was_skipped(): void
    {
        $job = $this->buildJob('eslint');
        $ctx = $this->contextWithNeeds(
            ['eslint' => ['yarn-install']],
            [],
            [],
            ['yarn-install']
        );

        $this->assertFalse($ctx->isJobReady($job));
    }

    /** @test */
    public function getBlockingNeeds_returns_unresolved_dependencies(): void
    {
        $job = $this->buildJob('eslint');
        $ctx = $this->contextWithNeeds(
            ['eslint' => ['yarn-install', 'install-deps']],
            ['yarn-install'],
            [],
            []
        );

        $this->assertSame(['install-deps'], $ctx->getBlockingNeeds($job));
    }

    /** @test */
    public function getBlockingNeeds_treats_failed_and_skipped_as_resolved_terminal_states(): void
    {
        $job = $this->buildJob('eslint');
        $ctx = $this->contextWithNeeds(
            ['eslint' => ['yarn-install', 'install-deps', 'fetch-assets']],
            ['fetch-assets'],
            ['yarn-install'],
            ['install-deps']
        );

        // Failed and skipped are terminal — they don't block, they propagate.
        $this->assertSame([], $ctx->getBlockingNeeds($job));
    }

    /** @test */
    public function getFailedOrSkippedNeeds_returns_terminal_blockers_with_their_kind(): void
    {
        $job = $this->buildJob('eslint');
        $ctx = $this->contextWithNeeds(
            ['eslint' => ['yarn-install', 'install-deps', 'ready']],
            ['ready'],
            ['yarn-install'],
            ['install-deps']
        );

        $this->assertSame(
            ['yarn-install' => 'failed', 'install-deps' => 'skipped'],
            $ctx->getFailedOrSkippedNeeds($job)
        );
    }

    /**
     * @param array<string, string[]> $needsByJob
     * @param string[] $completed
     * @param string[] $failed
     * @param string[] $skipped
     */
    private function contextWithNeeds(
        array $needsByJob,
        array $completed,
        array $failed,
        array $skipped
    ): AdmissionContext {
        return new AdmissionContext(
            10,
            null,
            [],
            [],
            $needsByJob,
            $completed,
            $failed,
            $skipped
        );
    }

    private function buildJob(string $name): JobAbstract
    {
        $config = new JobConfiguration($name, 'phpcs', []);
        return new PhpcsJob($config);
    }

    /**
     * @test
     *
     * A job without an entry in `memoryReserveByJob` must use 0 (no
     * reservation declared). With memoryFree=0 the fits comparison is
     * `0 <= 0` → true (DecrementInteger to -1 would also pass; the
     * killable mutant is IncrementInteger to 1 → `1 <= 0` → false).
     *
     * To make the boundary cover BOTH mutants we also check a positive
     * memoryFree with the absent entry — a Decrement to -1 changes
     * nothing observable (still <= memoryFree), but a value far below 0
     * could be detected by chaining a strict comparison if we observe
     * the internal value via fits + memoryFree=0 edge.
     */
    public function fits_uses_zero_when_job_has_no_memory_reservation_recorded(): void
    {
        $job = $this->buildJob('noreserve');
        // memoryFree=0 + no reservation: 0 <= 0 → true.
        // Mutated to ?? 1: 1 <= 0 → false → would deny admission.
        $ctx = new AdmissionContext(
            1,
            0,
            ['noreserve' => 1],
            [] // no entry for 'noreserve'
        );

        $this->assertTrue($ctx->fits($job));
    }

    /**
     * @test
     *
     * Multiple needs where the FIRST is in completedJobs and the second
     * is blocking. With `continue`, the foreach proceeds to evaluate the
     * second dep and appends it. With `break`, the second dep is never
     * scanned and the blocking list is empty.
     */
    public function blocking_needs_continues_past_completed_dependencies(): void
    {
        $ctx = $this->contextWithNeeds(
            ['j' => ['a', 'b']],
            ['a'],   // completed
            [],      // failed
            []       // skipped
        );

        $job = $this->buildJob('j');
        $this->assertSame(['b'], $ctx->getBlockingNeeds($job));
    }

    /**
     * @test
     *
     * Multiple needs where the FIRST is in failedJobs and the second is
     * blocking. Same rationale as above but for the failed-jobs bucket.
     */
    public function blocking_needs_continues_past_failed_dependencies(): void
    {
        $ctx = $this->contextWithNeeds(
            ['j' => ['a', 'b']],
            [],      // completed
            ['a'],   // failed
            []       // skipped
        );

        $job = $this->buildJob('j');
        $this->assertSame(['b'], $ctx->getBlockingNeeds($job));
    }

    /**
     * @test
     *
     * Multiple needs ALL pending (no bucket): blocking must contain ALL
     * of them in declaration order. The ArrayOneItem mutant would reduce
     * the result to a single element.
     */
    public function blocking_needs_accumulates_all_pending_dependencies(): void
    {
        $ctx = $this->contextWithNeeds(
            ['j' => ['a', 'b', 'c']],
            [],
            [],
            []
        );

        $job = $this->buildJob('j');
        $this->assertSame(['a', 'b', 'c'], $ctx->getBlockingNeeds($job));
    }
}
