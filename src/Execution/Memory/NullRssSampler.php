<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution\Memory;

/**
 * Fallback sampler used on platforms where no RSS source is supported in
 * this iteration (macOS, Windows). Returns no samples; the executor reads
 * isAvailable() at startup, emits a single warning on stderr and disables
 * memory thresholds for the run (CON-002, REQ-038).
 */
final class NullRssSampler implements MemorySampler
{
    private string $reason;

    public function __construct(string $reason)
    {
        $this->reason = $reason;
    }

    public function sample(array $jobNameToPid): array
    {
        return [];
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function getUnavailableReason(): string
    {
        return $this->reason;
    }
}
