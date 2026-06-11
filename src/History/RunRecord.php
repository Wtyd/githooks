<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\History;

/**
 * Read-only view over one persisted run (a decoded JSON v2 payload) plus the
 * basename it came from. Exposes the handful of fields the `profile` commands
 * need; metric extraction lives in {@see RunHistoryReader}.
 */
class RunRecord
{
    /** @var array<string, mixed> */
    private array $payload;

    private string $sourceFile;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(array $payload, string $sourceFile)
    {
        $this->payload = $payload;
        $this->sourceFile = $sourceFile;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getSourceFile(): string
    {
        return $this->sourceFile;
    }

    /** ISO-8601 start time from the runtime block, or null when not recorded. */
    public function getStartedAt(): ?string
    {
        $runtime = $this->payload['runtime'] ?? null;
        if (is_array($runtime) && isset($runtime['startedAt']) && is_string($runtime['startedAt'])) {
            return $runtime['startedAt'];
        }
        return null;
    }

    /**
     * Human label for the run: the ISO start time when present, otherwise the
     * `Ymd-His` timestamp recovered from the filename.
     */
    public function getTimestampLabel(): string
    {
        $startedAt = $this->getStartedAt();
        if ($startedAt !== null) {
            return $startedAt;
        }
        if (preg_match('/^(\d{8}-\d{6})/', $this->sourceFile, $matches) === 1) {
            return $matches[1];
        }
        return $this->sourceFile;
    }

    /** Stable chronological sort key: prefer startedAt, fall back to filename. */
    public function getSortKey(): string
    {
        return ($this->getStartedAt() ?? '') . '|' . $this->sourceFile;
    }

    /**
     * Calendar day of the run as `YYYY-MM-DD`, for the `--since` filter. Derived
     * from startedAt when present, otherwise from the `Ymd` filename prefix.
     * Null when neither is parseable.
     */
    public function getDate(): ?string
    {
        $startedAt = $this->getStartedAt();
        if ($startedAt !== null && preg_match('/^(\d{4}-\d{2}-\d{2})/', $startedAt, $matches) === 1) {
            return $matches[1];
        }
        if (preg_match('/^(\d{4})(\d{2})(\d{2})-/', $this->sourceFile, $matches) === 1) {
            return "$matches[1]-$matches[2]-$matches[3]";
        }
        return null;
    }

    public function getTotalTime(): string
    {
        $value = $this->payload['totalTime'] ?? '';
        return is_string($value) ? $value : '';
    }

    public function getPassed(): int
    {
        return $this->intField('passed');
    }

    public function getFailed(): int
    {
        return $this->intField('failed');
    }

    public function getSkipped(): int
    {
        return $this->intField('skipped');
    }

    private function intField(string $key): int
    {
        $value = $this->payload[$key] ?? 0;
        return is_int($value) ? $value : 0;
    }
}
