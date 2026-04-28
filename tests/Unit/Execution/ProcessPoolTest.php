<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Execution\Admission\FifoAdmission;
use Wtyd\GitHooks\Execution\Admission\GreedyAdmission;
use Wtyd\GitHooks\Execution\ProcessPool;
use Wtyd\GitHooks\Jobs\CustomJob;

class ProcessPoolTest extends TestCase
{
    /** @test */
    function constructor_clamps_max_processes_to_at_least_one()
    {
        $pool = new ProcessPool(0);
        $pool->enqueue([$this->makeJob('a', 'true')]);

        $started = $pool->fillPool();

        $this->assertCount(1, $started);
    }

    /** @test */
    function constructor_clamps_negative_max_processes_to_one()
    {
        $pool = new ProcessPool(-5);
        $pool->enqueue([$this->makeJob('a', 'true'), $this->makeJob('b', 'true')]);

        $started = $pool->fillPool();

        $this->assertCount(1, $started);
    }

    /** @test */
    function fillPool_starts_up_to_max_processes_jobs()
    {
        $pool = new ProcessPool(2);
        $pool->enqueue([
            $this->makeJob('a', 'sleep 0.3'),
            $this->makeJob('b', 'sleep 0.3'),
            $this->makeJob('c', 'sleep 0.3'),
        ]);

        $started = $pool->fillPool();

        $this->assertCount(2, $started);
        $this->assertSame(['a', 'b'], array_keys($started));
        $this->assertCount(2, $pool->getRunning());
        $this->assertCount(1, $pool->getQueuedJobs());

        $pool->terminateAll();
    }

    /** @test */
    function fillPool_returns_empty_when_queue_is_empty()
    {
        $pool = new ProcessPool(4);

        $this->assertSame([], $pool->fillPool());
    }

    /** @test */
    function fillPool_returns_only_newly_started_entries()
    {
        $pool = new ProcessPool(3);
        $pool->enqueue([
            $this->makeJob('a', 'sleep 0.3'),
            $this->makeJob('b', 'sleep 0.3'),
        ]);
        $pool->fillPool();

        $pool->enqueue([$this->makeJob('c', 'sleep 0.3')]);
        $second = $pool->fillPool();

        $this->assertSame(['c'], array_keys($second));
        $this->assertCount(3, $pool->getRunning());

        $pool->terminateAll();
    }

    /** @test */
    function fillPool_respects_existing_running_slots()
    {
        $pool = new ProcessPool(2);
        $pool->enqueue([$this->makeJob('a', 'sleep 0.3')]);
        $pool->fillPool();
        $this->assertCount(1, $pool->getRunning());

        $pool->enqueue([$this->makeJob('b', 'sleep 0.3'), $this->makeJob('c', 'sleep 0.3')]);
        $pool->fillPool();

        $this->assertCount(2, $pool->getRunning());
        $this->assertCount(1, $pool->getQueuedJobs());

        $pool->terminateAll();
    }

    /** @test */
    function pollCompleted_returns_finished_processes_and_removes_them_from_running()
    {
        $pool = new ProcessPool(2);
        $pool->enqueue([$this->makeJob('fast', 'true'), $this->makeJob('slow', 'sleep 1')]);
        $pool->fillPool();

        $this->waitForFastJob($pool);
        $completed = $pool->pollCompleted();

        $this->assertArrayHasKey('fast', $completed);
        $this->assertArrayNotHasKey('slow', $completed);
        $this->assertArrayNotHasKey('fast', $pool->getRunning());
        $this->assertArrayHasKey('slow', $pool->getRunning());

        $pool->terminateAll();
    }

    /** @test */
    function pollCompleted_returns_empty_when_all_processes_are_still_running()
    {
        $pool = new ProcessPool(2);
        $pool->enqueue([$this->makeJob('a', 'sleep 0.5'), $this->makeJob('b', 'sleep 0.5')]);
        $pool->fillPool();

        $this->assertSame([], $pool->pollCompleted());
        $this->assertCount(2, $pool->getRunning());

        $pool->terminateAll();
    }

    /** @test */
    function terminateAll_stops_all_running_processes_and_returns_them()
    {
        $pool = new ProcessPool(2);
        $pool->enqueue([$this->makeJob('a', 'sleep 10'), $this->makeJob('b', 'sleep 10')]);
        $pool->fillPool();

        $terminated = $pool->terminateAll();

        $this->assertCount(2, $terminated);
        $this->assertSame([], $pool->getRunning());
        foreach ($terminated as $entry) {
            $this->assertFalse($entry['process']->isRunning());
        }
    }

    /** @test */
    function terminateAll_returns_empty_when_pool_is_empty()
    {
        $pool = new ProcessPool(2);

        $this->assertSame([], $pool->terminateAll());
    }

    /** @test */
    function hasWork_is_true_when_queue_has_jobs()
    {
        $pool = new ProcessPool(2);
        $pool->enqueue([$this->makeJob('a', 'true')]);

        $this->assertTrue($pool->hasWork());
    }

    /** @test */
    function hasWork_is_true_when_running_has_jobs()
    {
        $pool = new ProcessPool(2);
        $pool->enqueue([$this->makeJob('a', 'sleep 0.3')]);
        $pool->fillPool();

        $this->assertTrue($pool->hasWork());

        $pool->terminateAll();
    }

    /** @test */
    function hasWork_is_false_when_queue_and_running_are_both_empty()
    {
        $pool = new ProcessPool(2);

        $this->assertFalse($pool->hasWork());
    }

    /** @test */
    function hasRunning_reflects_running_processes_only()
    {
        $pool = new ProcessPool(2);
        $this->assertFalse($pool->hasRunning());

        $pool->enqueue([$this->makeJob('a', 'true')]);
        $this->assertFalse($pool->hasRunning());

        $pool->fillPool();
        $this->assertTrue($pool->hasRunning());

        $pool->terminateAll();
    }

    /** @test */
    function clearQueue_discards_queued_jobs_without_touching_running()
    {
        $pool = new ProcessPool(1);
        $pool->enqueue([$this->makeJob('a', 'sleep 0.3'), $this->makeJob('b', 'sleep 0.3')]);
        $pool->fillPool();
        $this->assertCount(1, $pool->getQueuedJobs());

        $pool->clearQueue();

        $this->assertSame([], $pool->getQueuedJobs());
        $this->assertCount(1, $pool->getRunning());

        $pool->terminateAll();
    }

    /**
     * @test
     * Protects the `setTimeout(null)` invariant. Symfony's Process defaults to
     * a 60-second timeout; a QA job that exceeds it dies with
     * ProcessTimedOutException. If a refactor drops the setTimeout call, this
     * test catches it immediately: every started process must report `null`
     * for its timeout.
     */
    function fillPool_starts_processes_without_a_timeout()
    {
        $pool = new ProcessPool(2);
        $pool->enqueue([
            $this->makeJob('a', 'sleep 0.3'),
            $this->makeJob('b', 'sleep 0.3'),
        ]);

        $pool->fillPool();

        foreach ($pool->getRunning() as $entry) {
            $this->assertNull($entry['process']->getTimeout());
        }

        $pool->terminateAll();
    }

    /** @test */
    function fifo_strategy_blocks_when_head_does_not_fit_memory_budget()
    {
        $heavy = $this->makeJobWithMemory('heavy', 'sleep 0.5', 2000);
        $light = $this->makeJobWithMemory('light', 'sleep 0.5', 100);

        $pool = new ProcessPool(
            10,
            new FifoAdmission(),
            500, // budget too small for heavy
            ['heavy' => 1, 'light' => 1],
            ['heavy' => 2000, 'light' => 100]
        );
        $pool->enqueue([$heavy, $light]);

        $started = $pool->fillPool();

        $this->assertSame([], array_keys($started), 'FIFO must block the queue when head does not fit');
        $this->assertCount(2, $pool->getQueuedJobs());
    }

    /** @test */
    function greedy_strategy_skips_blocked_head_and_admits_smaller_jobs()
    {
        $heavy = $this->makeJobWithMemory('heavy', 'sleep 0.5', 2000);
        $light = $this->makeJobWithMemory('light', 'sleep 0.5', 100);

        $pool = new ProcessPool(
            10,
            new GreedyAdmission(),
            500,
            ['heavy' => 1, 'light' => 1],
            ['heavy' => 2000, 'light' => 100]
        );
        $pool->enqueue([$heavy, $light]);

        $started = $pool->fillPool();

        $this->assertArrayHasKey('light', $started);
        $this->assertArrayNotHasKey('heavy', $started);

        $pool->terminateAll();
    }

    /** @test */
    function memory_reserve_is_released_when_a_job_completes()
    {
        $first = $this->makeJobWithMemory('first', 'true', 400);
        $second = $this->makeJobWithMemory('second', 'sleep 0.5', 400);
        $third = $this->makeJobWithMemory('third', 'sleep 0.5', 400);

        $pool = new ProcessPool(
            10,
            new FifoAdmission(),
            800,
            ['first' => 1, 'second' => 1, 'third' => 1],
            ['first' => 400, 'second' => 400, 'third' => 400]
        );
        $pool->enqueue([$first, $second, $third]);

        $startedRound1 = $pool->fillPool();
        $this->assertSame(['first', 'second'], array_keys($startedRound1));
        // 800 reservados — third no puede arrancar todavía.
        $startedRound1b = $pool->fillPool();
        $this->assertSame([], array_keys($startedRound1b));

        // Esperar a que first termine y soltar su reserva.
        $this->waitForJob($pool, 'first');
        $pool->pollCompleted();

        $startedRound2 = $pool->fillPool();
        $this->assertArrayHasKey('third', $startedRound2);

        $pool->terminateAll();
    }

    /** @test */
    function getRunningPids_returns_pids_of_running_processes_only()
    {
        $pool = new ProcessPool(2);
        $pool->enqueue([$this->makeJob('alpha', 'sleep 0.5')]);
        $pool->fillPool();

        $pids = $pool->getRunningPids();

        $this->assertArrayHasKey('alpha', $pids);
        $this->assertGreaterThan(0, $pids['alpha']);

        $pool->terminateAll();
    }

    private function makeJob(string $name, string $script): CustomJob
    {
        return new CustomJob(new JobConfiguration($name, 'custom', ['script' => $script]));
    }

    private function makeJobWithMemory(string $name, string $script, int $memoryMb): CustomJob
    {
        return new CustomJob(new JobConfiguration($name, 'custom', [
            'script' => $script,
            'memory' => $memoryMb,
        ]));
    }

    private function waitForJob(ProcessPool $pool, string $name): void
    {
        $deadline = microtime(true) + 2.0;
        while (microtime(true) < $deadline) {
            foreach ($pool->getRunning() as $jobName => $entry) {
                if ($jobName === $name && !$entry['process']->isRunning()) {
                    return;
                }
            }
            usleep(20_000);
        }
    }

    private function waitForFastJob(ProcessPool $pool): void
    {
        $deadline = microtime(true) + 2.0;
        while (microtime(true) < $deadline) {
            foreach ($pool->getRunning() as $entry) {
                if (!$entry['process']->isRunning()) {
                    return;
                }
            }
            usleep(20_000);
        }
    }
}
