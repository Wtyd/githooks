<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Wtyd\GitHooks\Utils\GitStagerInterface;

class GitStagerFake implements GitStagerInterface
{
    private int $timesCalled = 0;

    private int $snapshotsTaken = 0;

    /**
     * Does not execute git commands. Records calls for assertions.
     *
     * @return void
     */
    public function stageTrackedFiles(): void
    {
        $this->timesCalled++;
    }

    /**
     * @return array<string, string>
     */
    public function snapshotStagedFiles(): array
    {
        $this->snapshotsTaken++;

        return [];
    }

    /**
     * Counted together with stageTrackedFiles(): both mean "the executor
     * re-staged", which is what the existing assertions check.
     *
     * @param array<string, string> $snapshot
     * @return void
     */
    public function stageModifiedSince(array $snapshot): void
    {
        $this->timesCalled++;
    }

    public function getSnapshotsTaken(): int
    {
        return $this->snapshotsTaken;
    }

    /**
     * @return int
     */
    public function getTimesCalled(): int
    {
        return $this->timesCalled;
    }

    /**
     * @return bool
     */
    public function wasCalled(): bool
    {
        return $this->timesCalled > 0;
    }
}
