<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Wtyd\GitHooks\Execution\Process\ProcessTerminator;

/**
 * ProcessTerminator with every OS seam replaced (BUG-35 decision table):
 * signals are appended to the FakeProcessTree's timeline as
 * `signal:<pid>:<signal>` and applied to its liveness set — a PID listed in
 * $ignoresTerm survives SIGTERM (15) and only dies on SIGKILL (9) —, the
 * clock is virtual (each pause() advances 50 ms, no real sleep) and stderr
 * warnings are captured.
 */
final class RecordingProcessTerminator extends ProcessTerminator
{
    private FakeProcessTree $fakeTree;

    /** @var int[] */
    private array $ignoresTerm;

    private float $clock = 0.0;

    /** @var string[] */
    public array $warnings = [];

    /**
     * @param int[] $ignoresTerm
     */
    public function __construct(FakeProcessTree $tree, int $graceMs, bool $canSignal = true, array $ignoresTerm = [])
    {
        parent::__construct($tree, $graceMs, $canSignal);
        $this->fakeTree = $tree;
        $this->ignoresTerm = $ignoresTerm;
    }

    protected function sendSignal(int $pid, int $signal): void
    {
        $this->fakeTree->calls[] = "signal:{$pid}:{$signal}";
        if ($signal === 9 || !in_array($pid, $this->ignoresTerm, true)) {
            $this->fakeTree->markDead($pid);
        }
    }

    protected function now(): float
    {
        return $this->clock;
    }

    protected function pause(): void
    {
        $this->clock += 0.05;
    }

    protected function emitWarning(string $message): void
    {
        $this->warnings[] = $message;
    }
}
