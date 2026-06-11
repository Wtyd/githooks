<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\History;

use Wtyd\GitHooks\Utils\Storage;

/**
 * Reads the persisted run history for a flow and extracts a single metric per
 * run (FEAT-5). Corrupt or unreadable payloads are skipped silently so a single
 * bad file never breaks `profile`.
 */
class RunHistoryReader
{
    public const METRIC_TIME = 'time';
    public const METRIC_PEAK_MEMORY = 'peak-memory';
    public const METRIC_PEAK_CORES = 'peak-cores';

    public const ALL_METRICS = [self::METRIC_TIME, self::METRIC_PEAK_MEMORY, self::METRIC_PEAK_CORES];

    /**
     * All runs persisted for `$flow`, oldest first.
     *
     * @return RunRecord[]
     */
    public function listRuns(string $flow): array
    {
        $slug = RunHistoryStore::slug($flow);

        $records = [];
        foreach (Storage::files(RunHistoryStore::HISTORY_DIR) as $file) {
            $basename = basename($file);
            if (!RunHistoryStore::matchesFlow($basename, $slug)) {
                continue;
            }
            $record = $this->decode($file, $basename);
            if ($record !== null) {
                $records[] = $record;
            }
        }

        usort($records, fn(RunRecord $left, RunRecord $right): int => strcmp($left->getSortKey(), $right->getSortKey()));

        return $records;
    }

    /**
     * Numeric value of `$metric` for `$record`. When `$job` is given the metric
     * is read from that job's entry; otherwise it is the flow-level value.
     * Returns null when the metric is not available in the payload (e.g.
     * peak-memory/peak-cores on a run not recorded with --stats, or a job not
     * present in the run). peak-cores is flow-level only: a non-null `$job`
     * yields null.
     */
    public function extractMetric(RunRecord $record, string $metric, ?string $job): ?float
    {
        if ($job !== null && $job !== '') {
            return $this->extractJobMetric($record, $metric, $job);
        }
        return $this->extractFlowMetric($record, $metric);
    }

    private function extractFlowMetric(RunRecord $record, string $metric): ?float
    {
        $payload = $record->getPayload();

        if ($metric === self::METRIC_TIME) {
            return $this->parseSeconds($payload['totalTime'] ?? null);
        }
        if ($metric === self::METRIC_PEAK_MEMORY) {
            return $this->nestedNumber($payload, ['stats', 'memory', 'flowPeak', 'value']);
        }
        if ($metric === self::METRIC_PEAK_CORES) {
            return $this->nestedNumber($payload, ['stats', 'cores', 'flowPeak', 'value']);
        }
        return null;
    }

    private function extractJobMetric(RunRecord $record, string $metric, string $job): ?float
    {
        // peak-cores is a flow-level allocation peak; it has no per-job value.
        if ($metric === self::METRIC_PEAK_CORES) {
            return null;
        }

        $entry = $this->findJob($record, $job);
        if ($entry === null) {
            return null;
        }
        if ($metric === self::METRIC_TIME) {
            return isset($entry['duration']) && is_numeric($entry['duration']) ? (float) $entry['duration'] : null;
        }
        if ($metric === self::METRIC_PEAK_MEMORY) {
            return isset($entry['memoryPeak']) && is_numeric($entry['memoryPeak']) ? (float) $entry['memoryPeak'] : null;
        }
        return null;
    }

    /** Whether the run recorded a job with the given name. */
    public function hasJob(RunRecord $record, string $job): bool
    {
        return $this->findJob($record, $job) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findJob(RunRecord $record, string $job): ?array
    {
        $jobs = $record->getPayload()['jobs'] ?? [];
        if (!is_array($jobs)) {
            return null;
        }
        foreach ($jobs as $entry) {
            if (is_array($entry) && ($entry['name'] ?? null) === $job) {
                return $entry;
            }
        }
        return null;
    }

    private function decode(string $file, string $basename): ?RunRecord
    {
        try {
            $content = Storage::get($file);
        } catch (\Throwable $e) {
            return null;
        }
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return null;
        }
        /** @var array<string, mixed> $decoded */
        return new RunRecord($decoded, $basename);
    }

    /**
     * Parse a "1.23s" duration string into seconds.
     *
     * @param mixed $value
     */
    private function parseSeconds($value): ?float
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        return is_numeric(rtrim($value, 's')) ? (float) rtrim($value, 's') : null;
    }

    /**
     * @param array<string, mixed> $payload
     * @param string[] $path
     */
    private function nestedNumber(array $payload, array $path): ?float
    {
        $cursor = $payload;
        foreach ($path as $key) {
            if (!is_array($cursor) || !array_key_exists($key, $cursor)) {
                return null;
            }
            $cursor = $cursor[$key];
        }
        return is_numeric($cursor) ? (float) $cursor : null;
    }
}
