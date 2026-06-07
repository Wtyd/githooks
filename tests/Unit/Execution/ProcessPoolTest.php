<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Execution\Admission\FifoAdmission;
use Wtyd\GitHooks\Execution\Admission\GreedyAdmission;
use Wtyd\GitHooks\Execution\JobResult;
use Wtyd\GitHooks\Execution\ProcessPool;
use Wtyd\GitHooks\Jobs\CustomJob;

class ProcessPoolTest extends UnitTestCase
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

    /**
     * @test
     *
     * FEAT-16: an inline job's pool entry carries no process (it ran in-process
     * at admission). terminateAll() must skip it via the null guard, not call
     * ->isRunning() on null. Kills the `&& → ||` mutant in terminateAll().
     */
    function terminate_all_tolerates_inline_entries_without_a_process()
    {
        $pool = new ProcessPool(2);
        $pool->enqueue([$this->makeInlineJob('inline_a'), $this->makeJob('shell_b', 'sleep 0.3')]);
        $pool->fillPool();

        // Must not throw on the null-process inline entry.
        $terminated = $pool->terminateAll();

        $this->assertArrayHasKey('inline_a', $terminated);
        $this->assertArrayHasKey('shell_b', $terminated);
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

    /**
     * @test
     * Mata el mutante ArrayOneItem en `getRunningPids` línea 269: si la mutación
     * trunca el array a un solo elemento, el assertCount(2) detecta la pérdida
     * del segundo PID.
     */
    function getRunningPids_returns_every_pid_when_multiple_processes_are_running()
    {
        $pool = new ProcessPool(3);
        $pool->enqueue([
            $this->makeJob('alpha', 'sleep 0.5'),
            $this->makeJob('beta', 'sleep 0.5'),
            $this->makeJob('gamma', 'sleep 0.5'),
        ]);
        $pool->fillPool();

        $pids = $pool->getRunningPids();

        $this->assertCount(3, $pids);
        $this->assertSame(['alpha', 'beta', 'gamma'], array_keys($pids));
        $this->assertGreaterThan(0, $pids['alpha']);
        $this->assertGreaterThan(0, $pids['beta']);
        $this->assertGreaterThan(0, $pids['gamma']);
        $this->assertNotSame($pids['alpha'], $pids['beta']);
        $this->assertNotSame($pids['beta'], $pids['gamma']);

        $pool->terminateAll();
    }

    /**
     * @test
     * Mata el mutante IncrementInteger en `buildAdmissionContext` línea 152:
     * `max(0, ...)` → `max(1, ...)`. Cuando el presupuesto está exactamente
     * agotado, real devuelve memoryFree=0 y bloquea el job de 1MB; mutado
     * devuelve memoryFree=1 y lo admite.
     */
    function memory_admission_blocks_when_budget_is_fully_used()
    {
        $heavy = $this->makeJobWithMemory('heavy', 'sleep 1', 200);
        $tiny = $this->makeJobWithMemory('tiny', 'sleep 1', 1);

        $pool = new ProcessPool(
            10,
            new FifoAdmission(),
            200,
            ['heavy' => 1, 'tiny' => 1],
            ['heavy' => 200, 'tiny' => 1]
        );
        $pool->enqueue([$heavy]);
        $pool->fillPool();

        $pool->enqueue([$tiny]);
        $started = $pool->fillPool();

        $this->assertSame([], array_keys($started));
        $this->assertSame(['tiny'], array_map(
            fn($j) => $j->getName(),
            $pool->getQueuedJobs()
        ));

        $pool->terminateAll();
    }

    /**
     * @test
     * Mata el mutante DecrementInteger en `releaseReservation` línea 191:
     * `(int) (...['reserve'] ?? 0)` → `... ?? -1`. Si liberamos un job que NO
     * está en `memoryReserveByJob`, el real resta 0 (deja `memoryReservedInUse`
     * en 0) y el mutado resta -1 (lo sube a 1), bloqueando un job que necesita
     * el budget completo.
     */
    function memory_release_uses_zero_default_for_jobs_not_in_reservation_map()
    {
        $unreserved = $this->makeJob('unreserved', 'true');
        $reserved = $this->makeJob('reserved', 'sleep 1');

        $pool = new ProcessPool(
            10,
            new FifoAdmission(),
            200,
            ['unreserved' => 1, 'reserved' => 1],
            ['reserved' => 200]
        );
        $pool->enqueue([$unreserved]);
        $pool->fillPool();

        $this->waitForJob($pool, 'unreserved');
        $pool->pollCompleted();

        $pool->enqueue([$reserved]);
        $started = $pool->fillPool();

        $this->assertArrayHasKey('reserved', $started);

        $pool->terminateAll();
    }

    /**
     * @test
     * Mata el mutante DecrementInteger en `terminateAll` línea 211:
     * `$this->memoryReservedInUse = 0` → `= -1`. Tras terminar, el contador debe
     * quedar exactamente en 0 — un -1 hace que el siguiente cálculo de
     * memoryFree devuelva `budget+1` y admita un job extra.
     */
    function terminateAll_resets_memory_accounting_to_exactly_zero()
    {
        $heavy = $this->makeJobWithMemory('heavy', 'sleep 5', 200);
        $big = $this->makeJobWithMemory('big', 'sleep 1', 200);
        $small = $this->makeJobWithMemory('small', 'sleep 1', 1);

        $pool = new ProcessPool(
            10,
            new FifoAdmission(),
            200,
            ['heavy' => 1, 'big' => 1, 'small' => 1],
            ['heavy' => 200, 'big' => 200, 'small' => 1]
        );
        $pool->enqueue([$heavy]);
        $pool->fillPool();
        $pool->terminateAll();

        $pool->enqueue([$big, $small]);
        $started = $pool->fillPool();

        $this->assertSame(['big'], array_keys($started));
        $this->assertSame(['small'], array_map(
            fn($j) => $j->getName(),
            $pool->getQueuedJobs()
        ));

        $pool->terminateAll();
    }

    /**
     * @test
     * Regression: when an uncontrollable job (e.g. PHPStan) reserves more cores
     * than the slot limit (maxParallelJobs), admission must still pick it once
     * previous jobs free their cores. Before the coresBudget split, $coresLimit
     * defaulted to $maxProcesses (the slot count), so $coresFree could never
     * reach the cost of a heavy uncontrollable job and FifoAdmission spun forever.
     *
     * Scenario: budget=4 cores total, maxParallel=2 slots, queue holds two
     * 1-core jobs and one 4-core job (uncontrollable). The two cheap jobs
     * fill the slots first; once they finish the heavy one MUST be admitted
     * (4 ≤ 4), not blocked by the slot count of 2.
     */
    function admission_uses_cores_budget_not_slot_limit_for_heavy_uncontrollable_jobs()
    {
        $cheapA = $this->makeJob('cheapA', 'true');
        $cheapB = $this->makeJob('cheapB', 'true');
        $heavy  = $this->makeJob('heavy', 'sleep 0.1');

        $pool = new ProcessPool(
            2,                      // maxParallel slots
            new FifoAdmission(),
            null,                   // no memory budget
            ['cheapA' => 1, 'cheapB' => 1, 'heavy' => 4],
            [],
            4                       // coresBudget — total cores available
        );
        $pool->enqueue([$cheapA, $cheapB, $heavy]);

        // Tick 1: cheapA + cheapB fill both slots (2 cores in use).
        $round1 = $pool->fillPool();
        $this->assertSame(['cheapA', 'cheapB'], array_keys($round1));

        // Wait for both 'true' jobs to exit and reclaim their cores.
        $this->waitForJob($pool, 'cheapA');
        $this->waitForJob($pool, 'cheapB');
        $pool->pollCompleted();

        // Tick 2: heavy has cores=4, coresFree must be 4 (full budget),
        // not 2 (slot count). Without the fix, fits() returns false forever.
        $round2 = $pool->fillPool();
        $this->assertArrayHasKey('heavy', $round2, 'Heavy uncontrollable job must be admitted under coresBudget=4');

        $pool->terminateAll();
    }

    /**
     * @test
     * Kills ProcessPool:165 Minus `coresLimit - coresInUse` -> `+`. The
     * existing tests cover the no-jobs-running case (inUse=0, where `-`
     * and `+` agree) and the saturation case where the slot count, not
     * coresFree, is what blocks. This case forces a head where the
     * remaining cores after admitting the first job decide the outcome.
     *
     * Setup: maxParallel=2 slots, coresBudget=4, queue [j1(cores=2),
     * big(cores=3)]. Tick 1 admits j1; tick 2 inspects big.
     *   Original: coresFree = 4 - 2 = 2 → big(3) does NOT fit → idle.
     *   Mutant:   coresFree = 4 + 2 = 6 → big(3) fits → admitted.
     */
    function admission_uses_subtraction_for_cores_free()
    {
        $j1 = $this->makeJob('j1', 'sleep 1');
        $big = $this->makeJob('big', 'sleep 1');

        $pool = new ProcessPool(
            2,
            new FifoAdmission(),
            null,
            ['j1' => 2, 'big' => 3],
            [],
            4
        );
        $pool->enqueue([$j1, $big]);

        $started = $pool->fillPool();

        $this->assertSame(['j1'], array_keys($started), 'Only j1 should be admitted; big needs 3 cores but only 2 are free under subtraction');
        $this->assertSame(['big'], array_map(fn($j) => $j->getName(), $pool->getQueuedJobs()));

        $pool->terminateAll();
    }

    /**
     * @test
     * Kills ProcessPool:227 DecrementInteger `coresInUse = 0` -> `-1` in
     * terminateAll(). After terminateAll the pool must report a CLEAN
     * snapshot (cores fully released). The mutant leaves an off-by-one
     * underflow that lets a job with cores > budget be admitted in the
     * next round.
     *
     * Setup: pool with coresBudget=2 admits a 2-core job, then terminates
     * everything. A subsequent enqueue of a 3-core job (coresByJob=3,
     * intentionally over budget — production clamps elsewhere, the pool
     * itself does not validate) must NOT be admitted because 3 > 2.
     *   Original: inUse = 0  → free = 2 - 0 = 2  → big(3) > 2 → no fit.
     *   Mutant:   inUse = -1 → free = 2 - (-1) = 3 → big(3) ≤ 3 → admitted.
     */
    function terminate_all_resets_cores_in_use_to_zero()
    {
        $j1 = $this->makeJob('j1', 'sleep 1');
        $big = $this->makeJob('big', 'sleep 0.1');

        $pool = new ProcessPool(
            2,
            new FifoAdmission(),
            null,
            ['j1' => 2, 'big' => 3],
            [],
            2
        );
        $pool->enqueue([$j1]);
        $pool->fillPool();
        $pool->terminateAll();

        $pool->enqueue([$big]);
        $started = $pool->fillPool();

        $this->assertEmpty(
            $started,
            'After terminateAll, big (cores=3) must NOT fit within coresBudget=2; '
                . 'a non-zero coresInUse residual would silently raise the effective budget'
        );

        $pool->terminateAll();
    }

    /**
     * @test
     * Kills ProcessPool:68 IncrementInteger `max(1, $coresBudget ?? maxProcesses)`
     * -> `max(2, ...)`. The constructor floor on coresBudget guards against
     * misconfigured budgets (zero or negative). The mutant raises the floor
     * to 2, which silently doubles the effective budget when the caller
     * passes 1 — letting a 2-core job through what was meant to be a single
     * core sandbox.
     *
     * Setup: pool with both maxProcesses=1 AND coresBudget=1, queue with a
     * 2-core job. Original blocks; mutant admits.
     */
    function cores_budget_floor_is_one_not_two()
    {
        $heavy = $this->makeJob('heavy', 'sleep 1');

        $pool = new ProcessPool(
            1,
            new FifoAdmission(),
            null,
            ['heavy' => 2],
            [],
            1
        );
        $pool->enqueue([$heavy]);

        $started = $pool->fillPool();

        $this->assertEmpty(
            $started,
            'A 2-core job must NOT be admitted when coresBudget=1; '
                . 'a floor of 2 in the constructor would silently raise the budget'
        );

        $pool->terminateAll();
    }

    /**
     * Infection mutant on ProcessPool:130 (DecrementInteger `?? 1` → `?? 0`):
     * a job started without an explicit entry in `coresByJob` must still
     * reserve 1 core. With the mutant `?? 0`, fillPool would let two jobs
     * coexist without consuming budget, leading to over-allocation.
     *
     * @test
     */
    function fillPool_reserves_one_core_per_job_without_explicit_cores_entry(): void
    {
        // Strategy != null forces the admission path that reads `coresInUse`.
        // coresBudget=2, two jobs with no entry in coresByJob → each must
        // add 1 core (the `?? 1` default), totalling 2.
        $pool = new ProcessPool(
            2,
            new GreedyAdmission(),
            null,            // memoryBudget
            [],              // coresByJob (empty — forces the ?? 1 default)
            [],              // memoryReserveByJob
            2                // coresBudget
        );
        $pool->enqueue([
            $this->makeJob('a', 'sleep 0.3'),
            $this->makeJob('b', 'sleep 0.3'),
        ]);

        $pool->fillPool();

        $coresInUse = new \ReflectionProperty(ProcessPool::class, 'coresInUse');
        $coresInUse->setAccessible(true);
        $this->assertSame(
            2,
            $coresInUse->getValue($pool),
            'Each started job without an explicit cores entry must reserve exactly 1 core (?? 1 default).'
        );

        $pool->terminateAll();
    }

    /**
     * Infection mutant on ProcessPool:224 (ReturnRemoval): without the early
     * `return` after pushing to `completedJobs`, a successful result also
     * leaks into `failedJobs` (double-counted). The two sets must stay
     * disjoint for `classifyTerminalBlockers` to propagate skips correctly.
     *
     * @test
     */
    function notify_result_success_does_not_also_add_to_failed_jobs(): void
    {
        $pool = new ProcessPool(2);
        $pool->notifyResult('jobA', true, false);  // success, not skipped

        $completedProp = new \ReflectionProperty(ProcessPool::class, 'completedJobs');
        $completedProp->setAccessible(true);
        $failedProp = new \ReflectionProperty(ProcessPool::class, 'failedJobs');
        $failedProp->setAccessible(true);

        $this->assertSame(['jobA'], $completedProp->getValue($pool));
        $this->assertSame(
            [],
            $failedProp->getValue($pool),
            'A successful result must not leak into failedJobs — the early return guards the disjointness.'
        );
    }

    private function makeJob(string $name, string $script): CustomJob
    {
        return new CustomJob(new JobConfiguration($name, 'custom', ['script' => $script]));
    }

    /** An inline job (FEAT-16): runs in-process, so its pool entry has no process. */
    private function makeInlineJob(string $name): CustomJob
    {
        return new class (new JobConfiguration($name, 'custom', ['script' => 'true'])) extends CustomJob {
            public function isInline(): bool
            {
                return true;
            }

            public function runInline(): JobResult
            {
                return new JobResult($this->name, true, '', '0ms', false, null, 'custom', 0);
            }
        };
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
