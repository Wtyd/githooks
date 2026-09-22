<?php

declare(strict_types=1);

namespace Tests\Unit\History;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\History\RunRecord;

/**
 * Direct coverage of the read-only view over a persisted run. The class had
 * no test file of its own; the sort key in particular is what
 * `RunHistoryReader` orders the whole history by, and it was only ever
 * observed through the resulting order.
 */
class RunRecordTest extends UnitTestCase
{
    /**
     * @param array<string, mixed> $payload
     */
    private function record(array $payload, string $sourceFile = '20260610-143005-qa.json'): RunRecord
    {
        return new RunRecord($payload, $sourceFile);
    }

    /**
     * @test
     *
     * The key is `<startedAt>|<sourceFile>`: the timestamp orders the runs and
     * the filename breaks ties between two runs recorded in the same second.
     * Asserting the exact string is what pins both halves and the separator —
     * ordering alone stays correct under a key that dropped the filename.
     */
    public function sort_key_is_the_start_time_joined_to_the_source_file(): void
    {
        $record = $this->record(['runtime' => ['startedAt' => '2026-06-10T14:30:05+00:00']]);

        $this->assertSame('2026-06-10T14:30:05+00:00|20260610-143005-qa.json', $record->getSortKey());
    }

    /**
     * @test
     *
     * Without a recorded start time the key degrades to an empty first half,
     * so such runs sort before every timestamped one and still break ties by
     * filename.
     */
    public function sort_key_falls_back_to_an_empty_timestamp_when_the_run_has_none(): void
    {
        $this->assertSame('|20260610-143005-qa.json', $this->record([])->getSortKey());
    }

    /**
     * @test
     * @dataProvider startedAtCases
     *
     * `startedAt` is read out of an untrusted JSON payload, so every shape
     * that is not a string under `runtime` has to come back as null.
     *
     * @param array<string, mixed> $payload
     */
    public function started_at_is_read_only_from_a_string_under_runtime(array $payload, ?string $expected): void
    {
        $this->assertSame($expected, $this->record($payload)->getStartedAt());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: ?string}>
     */
    public function startedAtCases(): array
    {
        return [
            'recorded'            => [['runtime' => ['startedAt' => '2026-06-10T14:30:05+00:00']], '2026-06-10T14:30:05+00:00'],
            'no runtime block'    => [[], null],
            'runtime not an array' => [['runtime' => 'nope'], null],
            'startedAt missing'   => [['runtime' => []], null],
            'startedAt not a string' => [['runtime' => ['startedAt' => 1750000000]], null],
        ];
    }

    /**
     * @test
     *
     * The human label prefers the ISO time and otherwise recovers the
     * `Ymd-His` prefix from the filename.
     */
    public function timestamp_label_recovers_the_prefix_from_the_filename_when_the_run_has_no_start_time(): void
    {
        $this->assertSame('20260610-143005', $this->record([])->getTimestampLabel());
        $this->assertSame('whatever.json', $this->record([], 'whatever.json')->getTimestampLabel());
    }
}
