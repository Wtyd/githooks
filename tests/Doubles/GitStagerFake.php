<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Wtyd\GitHooks\Utils\GitStagerInterface;

class GitStagerFake implements GitStagerInterface
{
    private int $timesCalled = 0;

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
