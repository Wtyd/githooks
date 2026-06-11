<?php

declare(strict_types=1);

namespace Tests\System\History;

use Tests\Utils\TestCase\SystemTestCase;
use Wtyd\GitHooks\History\RunHistoryReader;
use Wtyd\GitHooks\History\RunHistoryStore;
use Wtyd\GitHooks\Utils\Storage;

/**
 * FEAT-5 · reading and metric extraction over the persisted history. Fixtures
 * are hand-written JSON v2 payloads so the metric factor table (time /
 * peak-memory / peak-cores × flow / job, with and without --stats) is covered
 * directly.
 */
class RunHistoryReaderTest extends SystemTestCase
{
    private RunHistoryReader $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reader = new RunHistoryReader();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function seedRun(string $timestamp, string $slug, array $payload): void
    {
        Storage::put(RunHistoryStore::HISTORY_DIR . "/$timestamp-$slug.json", strval(json_encode($payload)));
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function payloadRun(string $startedAt, string $totalTime, array $extra = []): array
    {
        return array_merge([
            'version'   => 2,
            'flow'      => 'qa',
            'totalTime' => $totalTime,
            'passed'    => 1,
            'failed'    => 0,
            'skipped'   => 0,
            'runtime'   => ['startedAt' => $startedAt],
            'jobs'      => [],
        ], $extra);
    }

    /** @test */
    public function it_lists_only_matching_flow_runs_oldest_first(): void
    {
        $this->seedRun('20260103-100000', 'qa', $this->payloadRun('2026-01-03T10:00:00+00:00', '3.00s'));
        $this->seedRun('20260101-100000', 'qa', $this->payloadRun('2026-01-01T10:00:00+00:00', '1.00s'));
        $this->seedRun('20260102-100000', 'tests', $this->payloadRun('2026-01-02T10:00:00+00:00', '9.00s')); // other flow

        $runs = $this->reader->listRuns('qa');

        $this->assertCount(2, $runs);
        $this->assertSame('2026-01-01T10:00:00+00:00', $runs[0]->getStartedAt());
        $this->assertSame('2026-01-03T10:00:00+00:00', $runs[1]->getStartedAt());
    }

    /** @test */
    public function it_skips_corrupt_payloads(): void
    {
        $this->seedRun('20260101-100000', 'qa', $this->payloadRun('2026-01-01T10:00:00+00:00', '1.00s'));
        Storage::put(RunHistoryStore::HISTORY_DIR . '/20260102-100000-qa.json', 'not json {{{');

        $this->assertCount(1, $this->reader->listRuns('qa'));
    }

    /** @test */
    public function it_extracts_flow_time_in_seconds(): void
    {
        $this->seedRun('20260101-100000', 'qa', $this->payloadRun('2026-01-01T10:00:00+00:00', '2.50s'));
        $run = $this->reader->listRuns('qa')[0];

        $this->assertSame(2.5, $this->reader->extractMetric($run, RunHistoryReader::METRIC_TIME, null));
    }

    /** @test */
    public function it_extracts_per_job_time_from_duration(): void
    {
        $this->seedRun('20260101-100000', 'qa', $this->payloadRun('2026-01-01T10:00:00+00:00', '5.00s', [
            'jobs' => [['name' => 'phpstan', 'duration' => 3.2, 'memoryPeak' => 120]],
        ]));
        $run = $this->reader->listRuns('qa')[0];

        $this->assertSame(3.2, $this->reader->extractMetric($run, RunHistoryReader::METRIC_TIME, 'phpstan'));
    }

    /** @test */
    public function peak_memory_is_null_without_stats_and_present_with_stats(): void
    {
        $this->seedRun('20260101-100000', 'qa', $this->payloadRun('2026-01-01T10:00:00+00:00', '1.00s')); // no stats
        $this->seedRun('20260102-100000', 'qa', $this->payloadRun('2026-01-02T10:00:00+00:00', '1.00s', [
            'stats' => ['cores' => ['flowPeak' => ['value' => 4]], 'memory' => ['flowPeak' => ['value' => 256]]],
        ]));

        $runs = $this->reader->listRuns('qa');

        $this->assertNull($this->reader->extractMetric($runs[0], RunHistoryReader::METRIC_PEAK_MEMORY, null));
        $this->assertSame(256.0, $this->reader->extractMetric($runs[1], RunHistoryReader::METRIC_PEAK_MEMORY, null));
        $this->assertSame(4.0, $this->reader->extractMetric($runs[1], RunHistoryReader::METRIC_PEAK_CORES, null));
    }

    /** @test */
    public function peak_cores_per_job_is_never_available(): void
    {
        $this->seedRun('20260101-100000', 'qa', $this->payloadRun('2026-01-01T10:00:00+00:00', '1.00s', [
            'stats' => ['cores' => ['flowPeak' => ['value' => 8]]],
        ]));
        $run = $this->reader->listRuns('qa')[0];

        $this->assertNull($this->reader->extractMetric($run, RunHistoryReader::METRIC_PEAK_CORES, 'phpstan'));
    }

    /** @test */
    public function unknown_job_yields_null_and_hasJob_is_false(): void
    {
        $this->seedRun('20260101-100000', 'qa', $this->payloadRun('2026-01-01T10:00:00+00:00', '1.00s', [
            'jobs' => [['name' => 'phpstan', 'duration' => 1.0]],
        ]));
        $run = $this->reader->listRuns('qa')[0];

        $this->assertNull($this->reader->extractMetric($run, RunHistoryReader::METRIC_TIME, 'ghost'));
        $this->assertFalse($this->reader->hasJob($run, 'ghost'));
        $this->assertTrue($this->reader->hasJob($run, 'phpstan'));
    }

    /** @test */
    public function listing_an_unknown_flow_returns_empty(): void
    {
        $this->assertSame([], $this->reader->listRuns('does-not-exist'));
    }
}
