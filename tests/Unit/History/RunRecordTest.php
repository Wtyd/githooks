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

    /**
     * @test
     *
     * The label recovers a *prefix*, so the digits have to be at the start of
     * the filename. Without the anchor, any name carrying that digit shape
     * anywhere is mistaken for a timestamped run, and the label shows a
     * fragment of the name instead of the name itself.
     */
    public function timestamp_label_does_not_mistake_digits_in_the_middle_for_the_prefix(): void
    {
        $this->assertSame(
            'backup-20260610-143005-qa.json',
            $this->record([], 'backup-20260610-143005-qa.json')->getTimestampLabel()
        );
    }

    /**
     * @test
     * @dataProvider counterCases
     *
     * The counters come out of an untrusted payload: anything that is not an
     * int reads as zero. Reporting the raw value instead would put a string
     * (or null) where the profile commands expect to do arithmetic.
     *
     * @param array<string, mixed> $payload
     */
    public function counters_fall_back_to_zero_for_anything_that_is_not_an_int(
        array $payload,
        int $expectedFailed,
        int $expectedSkipped
    ): void {
        $record = $this->record($payload);

        $this->assertSame($expectedFailed, $record->getFailed());
        $this->assertSame($expectedSkipped, $record->getSkipped());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: int, 2: int}>
     */
    public function counterCases(): array
    {
        return [
            'both recorded as ints' => [['failed' => 2, 'skipped' => 3], 2, 3],
            'absent'                => [[], 0, 0],
            'recorded as strings'   => [['failed' => '2', 'skipped' => '3'], 0, 0],
            'recorded as null'      => [['failed' => null, 'skipped' => null], 0, 0],
        ];
    }
}
