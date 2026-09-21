<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Wtyd\GitHooks\Execution\Process\ProcessTree;

/**
 * Scripted `ProcessTree` for ProcessTerminator's decision table (BUG-35):
 * descendants per root are declared up front, liveness is a mutable set the
 * test (or a recording terminator) shrinks with `markDead()`, and every call
 * lands in `$calls` so a test can assert the timeline — in particular that
 * every enumeration happens BEFORE the first signal.
 */
final class FakeProcessTree implements ProcessTree
{
    /** @var array<int, int[]> rootPid → descendants in BFS order */
    private array $descendantsByRoot;

    /** @var array<int, true> */
    private array $alive = [];

    private bool $available;

    private string $reason;

    /** @var string[] `descendants:<pid>` | `alive` | anything a collaborator appends */
    public array $calls = [];

    /**
     * @param array<int, int[]> $descendantsByRoot
     * @param int[]             $alivePids
     */
    public function __construct(
        array $descendantsByRoot = [],
        array $alivePids = [],
        bool $available = true,
        string $reason = ''
    ) {
        $this->descendantsByRoot = $descendantsByRoot;
        foreach ($alivePids as $pid) {
            $this->alive[$pid] = true;
        }
        $this->available = $available;
        $this->reason = $reason;
    }

    public function markDead(int $pid): void
    {
        unset($this->alive[$pid]);
    }

    public function descendants(int $rootPid): array
    {
        $this->calls[] = "descendants:{$rootPid}";
        return $this->descendantsByRoot[$rootPid] ?? [];
    }

    public function alive(array $pids): array
    {
        $this->calls[] = 'alive';
        $alive = [];
        foreach ($pids as $pid) {
            if (isset($this->alive[$pid])) {
                $alive[] = $pid;
            }
        }
        return $alive;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function getUnavailableReason(): string
    {
        return $this->reason;
    }
}
