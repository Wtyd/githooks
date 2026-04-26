<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

/**
 * Per-job slice of an InputFilesResolution: which input files matched the job's
 * configured paths, and how many were available before the per-job filter.
 *
 * Used by accelerable jobs only. Non-accelerable jobs do not produce this object
 * (see §4.5.3 of spec-design-files-flag.md — the JSON key is omitted, not null).
 */
class InputFilesPerJob
{
    /** @var string[] */
    private array $matched;

    private int $totalAvailable;

    /**
     * @param string[] $matched
     */
    public function __construct(array $matched, int $totalAvailable)
    {
        $this->matched = array_values($matched);
        $this->totalAvailable = $totalAvailable;
    }

    /** @return string[] */
    public function getMatched(): array
    {
        return $this->matched;
    }

    public function getMatchedCount(): int
    {
        return count($this->matched);
    }

    public function getTotalAvailable(): int
    {
        return $this->totalAvailable;
    }

    /**
     * @return array{matched: string[], matchedCount: int, totalAvailable: int}
     */
    public function toArray(): array
    {
        return [
            'matched'        => $this->matched,
            'matchedCount'   => $this->getMatchedCount(),
            'totalAvailable' => $this->totalAvailable,
        ];
    }
}
