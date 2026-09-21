<?php

declare(strict_types=1);

namespace Tests\Unit\Execution\Process;

use Tests\Doubles\FakeProcess;
use Tests\Doubles\FakeProcessTree;
use Tests\Doubles\RecordingProcessTerminator;
use Tests\Utils\TestCase\UnitTestCase;

/**
 * Decision table of `ProcessTerminator::terminate()` — see
 * tests/Unit/Execution/factors.md §6 (BUG-35). Every row asserts the full
 * timeline recorded by FakeProcessTree (`descendants:<pid>`, `alive`,
 * `signal:<pid>:<15|9>`) so the order invariants are pinned, not just the
 * final state: enumerate before signalling (I2), leaf → root (I3), KILL
 * only after the grace period (I4), never our own PID nor PID 1 (I5).
 */
class ProcessTerminatorTest extends UnitTestCase
{
    private const TERM = 15;
    private const KILL = 9;

    /** Row 5 — the bug itself: a 3-level tree dies leaf → root on TERM alone, then the wrapper is reaped. */
    /** @test */
    public function three_level_tree_is_enumerated_first_and_signalled_leaf_to_root(): void
    {
        $tree = new FakeProcessTree([100 => [200, 300]], [100, 200, 300]);
        $terminator = new RecordingProcessTerminator($tree, 5000);
        $wrapper = (new FakeProcess())->withPid(100);

        $terminator->terminate([$wrapper]);

        $this->assertSame([
            'descendants:100',
            'signal:300:' . self::TERM,
            'signal:200:' . self::TERM,
            'signal:100:' . self::TERM,
            'alive',
        ], $tree->calls);
        $this->assertFalse($wrapper->isRunning(), 'Process::stop(0) must reap the wrapper');
        $this->assertSame([], $terminator->warnings);
    }

    /** Row 4 — no descendants: only the wrapper gets TERM. */
    /** @test */
    public function wrapper_without_descendants_receives_only_its_own_term(): void
    {
        $tree = new FakeProcessTree([100 => []], [100]);
        $terminator = new RecordingProcessTerminator($tree, 5000);

        $terminator->terminate([(new FakeProcess())->withPid(100)]);

        $this->assertSame(['descendants:100', 'signal:100:' . self::TERM, 'alive'], $tree->calls);
    }

    /** Row 6 — a child ignores TERM: KILL goes to that PID only, and only once the grace is exhausted. */
    /** @test */
    public function survivor_of_term_is_killed_only_after_the_grace_period(): void
    {
        $tree = new FakeProcessTree([100 => [200, 300]], [100, 200, 300]);
        // 100 ms grace, 50 ms per poll: alive() at t=0, t=0.05 and t=0.10, then KILL.
        $terminator = new RecordingProcessTerminator($tree, 100, true, [200]);

        $terminator->terminate([(new FakeProcess())->withPid(100)]);

        $this->assertSame([
            'descendants:100',
            'signal:300:' . self::TERM,
            'signal:200:' . self::TERM,
            'signal:100:' . self::TERM,
            'alive',
            'alive',
            'alive',
            'signal:200:' . self::KILL,
        ], $tree->calls);
    }

    /**
     * Row 8 — grace 0 (and negative, clamped to 0): KILL right after the first liveness check.
     *
     * @test
     * @dataProvider zeroGraceValues
     */
    public function zero_grace_kills_survivors_without_waiting(int $graceMs): void
    {
        $tree = new FakeProcessTree([100 => [200]], [100, 200]);
        $terminator = new RecordingProcessTerminator($tree, $graceMs, true, [200]);

        $terminator->terminate([(new FakeProcess())->withPid(100)]);

        $this->assertSame([
            'descendants:100',
            'signal:200:' . self::TERM,
            'signal:100:' . self::TERM,
            'alive',
            'signal:200:' . self::KILL,
        ], $tree->calls);
    }

    /** @return array<string, array{int}> */
    public function zeroGraceValues(): array
    {
        return [
            'grace = 0'          => [0],
            'grace < 0 (clamp)'  => [-1],
        ];
    }

    /** Row 7 — a grandchild that is already a zombie is signalled (harmless) but never waited for nor KILLed. */
    /** @test */
    public function zombie_descendant_is_not_treated_as_a_survivor(): void
    {
        $tree = new FakeProcessTree([100 => [200, 300]], [100, 200]); // 300 already a zombie
        $terminator = new RecordingProcessTerminator($tree, 5000);

        $terminator->terminate([(new FakeProcess())->withPid(100)]);

        $this->assertSame([
            'descendants:100',
            'signal:300:' . self::TERM,
            'signal:200:' . self::TERM,
            'signal:100:' . self::TERM,
            'alive',
        ], $tree->calls);
    }

    /** Row 9 — two jobs whose trees share a PID: both enumerated before the first signal, each PID signalled once. */
    /** @test */
    public function every_tree_is_enumerated_before_the_first_signal_and_shared_pids_are_signalled_once(): void
    {
        $tree = new FakeProcessTree([100 => [200, 300], 500 => [300, 600]], [100, 200, 300, 500, 600]);
        $terminator = new RecordingProcessTerminator($tree, 5000);
        $first = (new FakeProcess())->withPid(100);
        $second = (new FakeProcess())->withPid(500);

        $terminator->terminate([$first, $second]);

        $this->assertSame([
            'descendants:100',
            'descendants:500',
            'signal:300:' . self::TERM,
            'signal:200:' . self::TERM,
            'signal:100:' . self::TERM,
            'signal:600:' . self::TERM,
            'signal:500:' . self::TERM,
            'alive',
        ], $tree->calls);
        $this->assertFalse($first->isRunning());
        $this->assertFalse($second->isRunning());
    }

    /** Row 10 — our own PID and PID 1 are never signalled, whatever the tree says. */
    /** @test */
    public function own_pid_and_pid_one_are_never_signalled(): void
    {
        $self = (int) getmypid();
        $tree = new FakeProcessTree([100 => [$self, 1, 200]], [100, $self, 1, 200]);
        $terminator = new RecordingProcessTerminator($tree, 5000);

        $terminator->terminate([(new FakeProcess())->withPid(100)]);

        $this->assertSame([
            'descendants:100',
            'signal:200:' . self::TERM,
            'signal:100:' . self::TERM,
            'alive',
        ], $tree->calls);
    }

    /** Row 3 — a process not yet forked (pid null) is reaped but never enumerated or signalled. */
    /** @test */
    public function process_without_pid_is_stopped_but_not_enumerated(): void
    {
        $tree = new FakeProcessTree([], []);
        $terminator = new RecordingProcessTerminator($tree, 5000);
        $wrapper = new FakeProcess();

        $terminator->terminate([$wrapper]);

        $this->assertSame([], $tree->calls);
        $this->assertFalse($wrapper->isRunning());
        $this->assertSame([], $terminator->warnings);
    }

    /**
     * Rows 1 and 2 — degraded path: no tree or no ext-posix → previous behaviour plus one stderr warning.
     *
     * @test
     * @dataProvider degradedPlatforms
     */
    public function falls_back_to_process_stop_with_a_warning_when_the_tree_cannot_be_killed(
        bool $treeAvailable,
        string $treeReason,
        bool $canSignal,
        string $expectedWarning
    ): void {
        $tree = new FakeProcessTree([100 => [200]], [100, 200], $treeAvailable, $treeReason);
        $terminator = new RecordingProcessTerminator($tree, 5000, $canSignal);
        $wrapper = (new FakeProcess())->withPid(100);

        $terminator->terminate([$wrapper]);

        $this->assertSame([], $tree->calls, 'no enumeration and no signal in the degraded path');
        $this->assertFalse($wrapper->isRunning(), 'Process::stop(0) is still the fallback');
        $this->assertSame([$expectedWarning], $terminator->warnings);
    }

    /** @return array<string, array{bool, string, bool, string}> */
    public function degradedPlatforms(): array
    {
        return [
            'tree unavailable, posix present' => [
                false,
                'process tree not available on Windows',
                true,
                '⚠ Process tree kill unavailable (process tree not available on Windows): '
                . 'falling back to Process::stop() on each job',
            ],
            'tree available, posix missing' => [
                true,
                '',
                false,
                '⚠ Process tree kill unavailable (ext-posix not loaded): falling back to Process::stop() on each job',
            ],
        ];
    }

    /** Row 0 — nothing in flight: no warning even on a degraded platform, nothing touched. */
    /** @test */
    public function empty_process_list_is_a_silent_no_op(): void
    {
        $tree = new FakeProcessTree([], [], false, 'process tree not available on Windows');
        $terminator = new RecordingProcessTerminator($tree, 5000);

        $terminator->terminate([]);

        $this->assertSame([], $tree->calls);
        $this->assertSame([], $terminator->warnings);
    }

    /** @test */
    public function default_grace_is_five_seconds(): void
    {
        $this->assertSame(5000, RecordingProcessTerminator::DEFAULT_GRACE_MS);
    }
}
