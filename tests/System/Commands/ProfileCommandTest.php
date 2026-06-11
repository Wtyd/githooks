<?php

declare(strict_types=1);

namespace Tests\System\Commands;

use Tests\Utils\TestCase\SystemTestCase;
use Wtyd\GitHooks\History\RunHistoryStore;
use Wtyd\GitHooks\Utils\Storage;

/**
 * FEAT-5 · `profile` command. Covers the filter/metric factor table: empty
 * history, single/flat/varied series, metric unavailable without --stats,
 * peak-cores per-job rejection, unknown job, --since/--last edges and option
 * validation.
 */
class ProfileCommandTest extends SystemTestCase
{
    /**
     * @param array<string, mixed> $extra
     */
    private function seedRun(string $timestamp, string $totalTime, array $extra = []): void
    {
        $payload = array_merge([
            'version'   => 2,
            'flow'      => 'qa',
            'totalTime' => $totalTime,
            'passed'    => 1,
            'failed'    => 0,
            'skipped'   => 0,
            'runtime'   => ['startedAt' => substr($timestamp, 0, 4) . '-' . substr($timestamp, 4, 2) . '-' . substr($timestamp, 6, 2) . 'T10:00:00+00:00'],
            'jobs'      => [],
        ], $extra);

        Storage::put(RunHistoryStore::HISTORY_DIR . "/$timestamp-qa.json", strval(json_encode($payload)));
    }

    /** @test */
    public function empty_history_reports_no_runs_and_exits_zero(): void
    {
        $this->artisan('profile qa')
            ->containsStringInOutput("No run history for flow 'qa'.")
            ->assertExitCode(0);
    }

    /** @test */
    public function single_run_has_no_trend(): void
    {
        $this->seedRun('20260101-100000', '4.20s');

        $this->artisan('profile qa')
            ->containsStringInOutput('trend: n/a')
            ->assertExitCode(0);
    }

    /** @test */
    public function varied_series_renders_percentiles_and_trend(): void
    {
        $this->seedRun('20260101-100000', '1.00s');
        $this->seedRun('20260102-100000', '2.00s');
        $this->seedRun('20260103-100000', '5.00s');
        $this->seedRun('20260104-100000', '6.00s');

        $this->artisan('profile qa')
            ->containsStringInOutput('min: 1s')
            ->containsStringInOutput('max: 6s')
            ->containsStringInOutput('vs prev 2')
            ->assertExitCode(0);
    }

    /** @test */
    public function peak_memory_without_stats_reports_unavailable(): void
    {
        $this->seedRun('20260101-100000', '1.00s'); // no stats block

        $this->artisan('profile qa --metric=peak-memory')
            ->containsStringInOutput('not available')
            ->containsStringInOutput('--stats')
            ->assertExitCode(0);
    }

    /** @test */
    public function peak_cores_with_job_is_rejected(): void
    {
        $this->artisan('profile qa --metric=peak-cores --job=phpstan')
            ->containsStringInOutput('flow-level')
            ->assertExitCode(1);
    }

    /** @test */
    public function unknown_job_reports_no_data(): void
    {
        $this->seedRun('20260101-100000', '1.00s', ['jobs' => [['name' => 'phpstan', 'duration' => 1.0]]]);

        $this->artisan('profile qa --job=ghost')
            ->containsStringInOutput("No data for job 'ghost'.")
            ->assertExitCode(0);
    }

    /** @test */
    public function since_in_the_future_reports_no_runs_since_date(): void
    {
        $this->seedRun('20260101-100000', '1.00s');

        $this->artisan('profile qa --since=2099-01-01')
            ->containsStringInOutput('since 2099-01-01')
            ->assertExitCode(0);
    }

    /** @test */
    public function last_zero_is_rejected(): void
    {
        $this->artisan('profile qa --last=0')
            ->containsStringInOutput('positive integer')
            ->assertExitCode(1);
    }

    /** @test */
    public function invalid_metric_is_rejected(): void
    {
        $this->artisan('profile qa --metric=bogus')
            ->containsStringInOutput("Invalid --metric 'bogus'")
            ->assertExitCode(1);
    }

    /** @test */
    public function invalid_format_is_rejected(): void
    {
        $this->artisan('profile qa --format=xml')
            ->containsStringInOutput("Invalid --format 'xml'")
            ->assertExitCode(1);
    }

    /** @test */
    public function json_format_emits_values_and_percentiles(): void
    {
        $this->seedRun('20260101-100000', '1.00s');
        $this->seedRun('20260102-100000', '3.00s');

        $this->artisan('profile qa --format=json')
            ->containsStringInOutput('"metric": "time"')
            ->containsStringInOutput('"values":')
            ->containsStringInOutput('"p95":')
            ->assertExitCode(0);
    }
}
