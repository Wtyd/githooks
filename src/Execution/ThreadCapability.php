<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

/**
 * Declares that a job type supports internal parallelism and how to control it.
 */
class ThreadCapability
{
    private string $argumentKey;

    private int $defaultThreads;

    private int $minimumThreads;

    private bool $controllable;

    public function __construct(string $argumentKey, int $defaultThreads, int $minimumThreads = 1, bool $controllable = true)
    {
        $this->argumentKey = $argumentKey;
        $this->defaultThreads = $defaultThreads;
        $this->minimumThreads = $minimumThreads;
        $this->controllable = $controllable;
    }

    public function getArgumentKey(): string
    {
        return $this->argumentKey;
    }

    public function getDefaultThreads(): int
    {
        return $this->defaultThreads;
    }

    public function getMinimumThreads(): int
    {
        return $this->minimumThreads;
    }

    public function isControllable(): bool
    {
        return $this->controllable;
    }
}
