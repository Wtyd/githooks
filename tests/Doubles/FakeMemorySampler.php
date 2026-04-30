<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Wtyd\GitHooks\Execution\Memory\MemorySampler;

/**
 * Test double for MemorySampler: returns a configurable per-job RSS map
 * on every sample() call and exposes the call history so tests can assert
 * which PIDs were sampled at each tick.
 */
final class FakeMemorySampler implements MemorySampler
{
    /** @var array<int, array<string, int>> Sequence of RSS maps to return per sample() call. */
    private array $rssSequence;

    private bool $available;

    private string $unavailableReason;

    /** @var array<int, array<string, int>> Recorded jobNameToPid arguments per call. */
    public array $calls = [];

    /**
     * @param array<int, array<string, int>> $rssSequence
     *   Per-call sequence of jobName→RSS maps. When more sample() calls happen
     *   than entries provided, the last entry is reused.
     */
    public function __construct(
        array $rssSequence = [[]],
        bool $available = true,
        string $unavailableReason = ''
    ) {
        $this->rssSequence = $rssSequence === [] ? [[]] : $rssSequence;
        $this->available = $available;
        $this->unavailableReason = $unavailableReason;
    }

    public function sample(array $jobNameToPid): array
    {
        $this->calls[] = $jobNameToPid;
        $idx = min(count($this->calls) - 1, count($this->rssSequence) - 1);
        return $this->rssSequence[$idx];
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function getUnavailableReason(): string
    {
        return $this->unavailableReason;
    }
}
