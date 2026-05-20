<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Execution\ProcessPool;
use Wtyd\GitHooks\Jobs\JobAbstract;
use Wtyd\GitHooks\Jobs\PhpcsJob;

/**
 * FEAT-3 · Group D — needs-aware pool primitives.
 *
 * Covers the pool's bookkeeping that the executor uses to gate admission and
 * propagate skip reasons. Process-spawning paths (fillPool / startJob) are
 * exercised by the integration test suite — this file isolates the pure
 * primitives so they can be unit-tested fast.
 */
class ProcessPoolNeedsTest extends TestCase
{
    /** @test */
    public function drainBlockedByFailedDeps_returns_empty_when_no_deps_have_failed(): void
    {
        $pool = $this->poolWith(
            ['eslint' => ['yarn-install']],
            [$this->buildJob('eslint')]
        );

        $drained = $pool->drainBlockedByFailedDeps();

        $this->assertSame([], $drained);
    }

    /** @test */
    public function drainBlockedByFailedDeps_skips_job_when_a_dep_failed(): void
    {
        $pool = $this->poolWith(
            ['eslint' => ['yarn-install']],
            [$this->buildJob('eslint')]
        );
        $pool->notifyResult('yarn-install', false, false);

        $drained = $pool->drainBlockedByFailedDeps();

        $this->assertCount(1, $drained);
        $this->assertTrue($drained[0]->isSkipped());
        $this->assertSame('needs yarn-install failed', $drained[0]->getSkipReason());
        $this->assertSame('eslint', $drained[0]->getJobName());
        $this->assertSame([], $pool->getQueuedJobs(), 'Drained jobs leave the queue');
    }

    /** @test */
    public function drainBlockedByFailedDeps_skips_job_when_a_dep_was_skipped(): void
    {
        $pool = $this->poolWith(
            ['eslint' => ['yarn-install']],
            [$this->buildJob('eslint')]
        );
        $pool->notifyResult('yarn-install', false, true);

        $drained = $pool->drainBlockedByFailedDeps();

        $this->assertCount(1, $drained);
        $this->assertSame('needs yarn-install was skipped', $drained[0]->getSkipReason());
    }

    /** @test */
    public function drainBlockedByFailedDeps_lists_multiple_failed_deps_in_one_message(): void
    {
        $pool = $this->poolWith(
            ['app' => ['compile', 'lint']],
            [$this->buildJob('app')]
        );
        $pool->notifyResult('compile', false, false);
        $pool->notifyResult('lint', false, false);

        $drained = $pool->drainBlockedByFailedDeps();

        $this->assertCount(1, $drained);
        $this->assertSame('needs compile, lint failed', $drained[0]->getSkipReason());
    }

    /** @test */
    public function drainBlockedByFailedDeps_lists_multiple_skipped_deps_in_one_message(): void
    {
        $pool = $this->poolWith(
            ['app' => ['compile', 'lint']],
            [$this->buildJob('app')]
        );
        $pool->notifyResult('compile', false, true);
        $pool->notifyResult('lint', false, true);

        $drained = $pool->drainBlockedByFailedDeps();

        $this->assertSame('needs compile, lint were skipped', $drained[0]->getSkipReason());
    }

    /** @test */
    public function drainBlockedByFailedDeps_mixes_failed_and_skipped_in_one_message(): void
    {
        $pool = $this->poolWith(
            ['app' => ['compile', 'lint']],
            [$this->buildJob('app')]
        );
        $pool->notifyResult('compile', false, false);
        $pool->notifyResult('lint', false, true);

        $drained = $pool->drainBlockedByFailedDeps();

        $this->assertSame('needs compile failed, lint was skipped', $drained[0]->getSkipReason());
    }

    /** @test */
    public function drainBlockedByFailedDeps_cascades_through_chain(): void
    {
        // yarn-install fails → eslint propagates → lint-fix propagates.
        $pool = $this->poolWith(
            [
                'eslint'   => ['yarn-install'],
                'lint-fix' => ['eslint'],
            ],
            [
                $this->buildJob('eslint'),
                $this->buildJob('lint-fix'),
            ]
        );
        $pool->notifyResult('yarn-install', false, false);

        $drained = $pool->drainBlockedByFailedDeps();

        $this->assertCount(2, $drained);
        // First call drains direct dependants only; cascading is per call.
        $names = array_map(fn($r) => $r->getJobName(), $drained);
        $this->assertContains('eslint', $names);
        // lint-fix is in the queue too; once eslint is in skippedJobs, lint-fix is
        // also drained on the same pass because the loop sees the updated set.
        $this->assertContains('lint-fix', $names);
    }

    /** @test */
    public function getWaitingByJob_returns_pending_blockers_per_queued_job(): void
    {
        $pool = $this->poolWith(
            [
                'eslint'   => ['yarn-install'],
                'phpstan'  => [],
                'lint-fix' => ['eslint'],
            ],
            [
                $this->buildJob('eslint'),
                $this->buildJob('phpstan'),
                $this->buildJob('lint-fix'),
            ]
        );

        $waiting = $pool->getWaitingByJob();

        $this->assertSame(['yarn-install'], $waiting['eslint']);
        $this->assertSame(['eslint'], $waiting['lint-fix']);
        $this->assertArrayNotHasKey('phpstan', $waiting);
    }

    /** @test */
    public function getWaitingByJob_excludes_failed_and_skipped_deps(): void
    {
        $pool = $this->poolWith(
            ['app' => ['fetch', 'compile', 'install']],
            [$this->buildJob('app')]
        );
        $pool->notifyResult('fetch', true, false);
        $pool->notifyResult('compile', false, true);

        $waiting = $pool->getWaitingByJob();

        // Only `install` is still pending. fetch is completed; compile is
        // skipped — that one would cause drainBlockedByFailedDeps to skip
        // `app`, but until then, waitingBy excludes both terminal states.
        $this->assertSame(['install'], $waiting['app']);
    }

    /**
     * @param array<string, string[]> $needsByJob
     * @param JobAbstract[] $jobs
     */
    private function poolWith(array $needsByJob, array $jobs): ProcessPool
    {
        $pool = new ProcessPool(10);
        $pool->enqueue($jobs);
        $pool->setNeedsByJob($needsByJob);
        return $pool;
    }

    private function buildJob(string $name): JobAbstract
    {
        return new PhpcsJob(new JobConfiguration($name, 'phpcs', []));
    }
}
