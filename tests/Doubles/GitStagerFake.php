<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Wtyd\GitHooks\Utils\GitStagerInterface;

class GitStagerFake implements GitStagerInterface
{
    private int $timesCalled = 0;

    private int $snapshotsTaken = 0;

    /**
     * @return array<string, string>
     */
    public function snapshotStagedFiles(): array
    {
        $this->snapshotsTaken++;

        return [];
    }

    /**
     * Does not execute git commands. Records the call: "the executor
     * re-staged" is what the assertions check.
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
