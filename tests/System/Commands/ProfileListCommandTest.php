<?php

declare(strict_types=1);

namespace Tests\System\Commands;

use Tests\Utils\TestCase\SystemTestCase;
use Wtyd\GitHooks\History\RunHistoryStore;
use Wtyd\GitHooks\Utils\Storage;

/**
 * FEAT-5 · `profile:list` command — lists persisted runs for a flow.
 */
class ProfileListCommandTest extends SystemTestCase
{
    private function seedRun(string $timestamp, string $totalTime, int $passed, int $failed): void
    {
        $payload = [
            'version'   => 2,
            'flow'      => 'qa',
            'totalTime' => $totalTime,
            'passed'    => $passed,
            'failed'    => $failed,
            'skipped'   => 0,
            'runtime'   => ['startedAt' => substr($timestamp, 0, 4) . '-01-01T10:00:00+00:00'],
            'jobs'      => [],
        ];
        Storage::put(RunHistoryStore::HISTORY_DIR . "/$timestamp-qa.json", strval(json_encode($payload)));
    }

    /** @test */
    public function it_reports_no_history_for_an_empty_flow(): void
    {
        $this->artisan('profile:list qa')
            ->containsStringInOutput("No run history for flow 'qa'.")
            ->assertExitCode(0);
    }

    /** @test */
    public function it_lists_persisted_runs_in_a_table(): void
    {
        $this->seedRun('20260101-100000', '1.50s', 3, 0);
        $this->seedRun('20260102-100000', '2.50s', 2, 1);

        $this->artisan('profile:list qa')
            ->containsStringInOutput('1.50s')
            ->containsStringInOutput('2.50s')
            ->assertExitCode(0);
    }

    /** @test */
    public function json_format_lists_runs(): void
    {
        $this->seedRun('20260101-100000', '1.50s', 3, 0);

        $this->artisan('profile:list qa --format=json')
            ->containsStringInOutput('"count": 1')
            ->containsStringInOutput('"totalTime": "1.50s"')
            ->assertExitCode(0);
    }

    /** @test */
    public function invalid_format_is_rejected(): void
    {
        $this->artisan('profile:list qa --format=xml')
            ->containsStringInOutput("Invalid --format 'xml'")
            ->assertExitCode(1);
    }
}
