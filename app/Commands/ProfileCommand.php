<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\App\Commands;

use LaravelZero\Framework\Commands\Command;
use Wtyd\GitHooks\History\Percentiles;
use Wtyd\GitHooks\History\RunHistoryReader;
use Wtyd\GitHooks\History\RunRecord;
use Wtyd\GitHooks\History\Sparkline;

/**
 * `profile <flow>` — trend of a metric over the persisted run history (FEAT-5):
 * an ASCII sparkline plus min/p50/p95/max and a trend versus the previous half.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Reader + sparkline + percentiles
 *   collaborators plus the value-objects they return.
 */
class ProfileCommand extends Command
{
    protected $signature = 'profile
                            {flow : The flow to profile}
                            {--job= : Restrict the metric to a single job}
                            {--metric= : Metric to chart: time, peak-memory or peak-cores (default: time)}
                            {--since= : Only runs on or after this date (YYYY-MM-DD)}
                            {--last= : Only the most recent N runs}
                            {--format= : Output format (text, json)}';

    protected $description = 'Show the trend of a metric across a flow run history';

    /** @var array<string, string> Display unit per metric. */
    private const UNITS = [
        RunHistoryReader::METRIC_TIME        => 's',
        RunHistoryReader::METRIC_PEAK_MEMORY => 'MB',
        RunHistoryReader::METRIC_PEAK_CORES  => 'cores',
    ];

    private RunHistoryReader $reader;

    public function __construct(RunHistoryReader $reader)
    {
        parent::__construct();
        $this->reader = $reader;
    }

    public function handle(): int
    {
        $options = $this->validatedOptions();
        if ($options === null) {
            return 1;
        }

        $flow = strval($this->argument('flow'));
        $runs = $this->filter($this->reader->listRuns($flow), $options['since'], $options['last']);

        if ($runs === []) {
            $this->info($this->emptyMessage($flow, $options['since']));
            return 0;
        }

        [$values, $excluded] = $this->collect($runs, $options['metric'], $options['job']);

        if ($values === []) {
            $this->info($this->noDataMessage($runs, $options['metric'], $options['job']));
            return 0;
        }

        $stats = Percentiles::compute($values);
        $sparkline = Sparkline::render($values);

        if ($options['format'] === 'json') {
            $this->line($this->renderJson($flow, $options, $values, $excluded, $stats, $sparkline));
            return 0;
        }

        $this->renderText($flow, $options, $values, $excluded, $stats, $sparkline);
        return 0;
    }

    /**
     * @return array{job: ?string, metric: string, since: ?string, last: ?int, format: string}|null
     */
    private function validatedOptions(): ?array
    {
        $metric = $this->option('metric') !== null ? strval($this->option('metric')) : RunHistoryReader::METRIC_TIME;
        if (!in_array($metric, RunHistoryReader::ALL_METRICS, true)) {
            $this->error("Invalid --metric '$metric'. Valid metrics: " . implode(', ', RunHistoryReader::ALL_METRICS) . '.');
            return null;
        }

        $format = $this->option('format') !== null ? strval($this->option('format')) : 'text';
        if (!in_array($format, ['text', 'json'], true)) {
            $this->error("Invalid --format '$format'. Valid formats: text, json.");
            return null;
        }

        $job = $this->option('job') !== null ? strval($this->option('job')) : null;
        if ($job !== null && $metric === RunHistoryReader::METRIC_PEAK_CORES) {
            $this->error("Metric 'peak-cores' is a flow-level metric and cannot be filtered by --job.");
            return null;
        }

        $since = $this->option('since') !== null ? strval($this->option('since')) : null;
        if ($since !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $since) !== 1) {
            $this->error("Invalid --since '$since'. Expected format YYYY-MM-DD.");
            return null;
        }

        $last = null;
        if ($this->option('last') !== null) {
            $raw = strval($this->option('last'));
            if (preg_match('/^\d+$/', $raw) !== 1 || (int) $raw < 1) {
                $this->error("Invalid --last '$raw'. Expected a positive integer (>= 1).");
                return null;
            }
            $last = (int) $raw;
        }

        return ['job' => $job, 'metric' => $metric, 'since' => $since, 'last' => $last, 'format' => $format];
    }

    /**
     * @param RunRecord[] $runs
     * @return RunRecord[]
     */
    private function filter(array $runs, ?string $since, ?int $last): array
    {
        if ($since !== null) {
            $runs = array_values(array_filter($runs, static function (RunRecord $run) use ($since): bool {
                $date = $run->getDate();
                return $date !== null && $date >= $since;
            }));
        }
        if ($last !== null && count($runs) > $last) {
            $runs = array_slice($runs, -$last);
        }
        return $runs;
    }

    /**
     * Extract the metric for each run; null values (metric absent for that run)
     * are dropped and counted as excluded.
     *
     * @param RunRecord[] $runs
     * @return array{0: float[], 1: int}
     */
    private function collect(array $runs, string $metric, ?string $job): array
    {
        $values = [];
        $excluded = 0;
        foreach ($runs as $run) {
            $value = $this->reader->extractMetric($run, $metric, $job);
            if ($value === null) {
                $excluded++;
                continue;
            }
            $values[] = $value;
        }
        return [$values, $excluded];
    }

    private function emptyMessage(string $flow, ?string $since): string
    {
        return $since !== null
            ? "No runs for flow '$flow' since $since."
            : "No run history for flow '$flow'.";
    }

    /**
     * @param RunRecord[] $runs
     */
    private function noDataMessage(array $runs, string $metric, ?string $job): string
    {
        if ($job !== null) {
            foreach ($runs as $run) {
                if ($this->reader->hasJob($run, $job)) {
                    return "Metric '$metric' not available for job '$job' — runs were not recorded with --stats.";
                }
            }
            return "No data for job '$job'.";
        }
        if ($metric !== RunHistoryReader::METRIC_TIME) {
            return "Metric '$metric' not available — runs were not recorded with --stats.";
        }
        return 'No data to profile.';
    }

    /**
     * @param array{job: ?string, metric: string, since: ?string, last: ?int, format: string} $options
     * @param float[] $values
     * @param array{min: float, p50: float, p95: float, max: float, trend: array{direction: string, percent: float|null, window: int}|null} $stats
     */
    private function renderText(string $flow, array $options, array $values, int $excluded, array $stats, string $sparkline): void
    {
        $subject = $options['job'] !== null ? $options['job'] : $flow;
        $count = count($values);
        $this->line("$subject · last $count runs · {$options['metric']}");
        $this->line('  ' . $sparkline);

        $unit = self::UNITS[$options['metric']];
        $line = sprintf(
            '  min: %s · p50: %s · p95: %s · max: %s · trend: %s',
            $this->fmt($stats['min'], $unit),
            $this->fmt($stats['p50'], $unit),
            $this->fmt($stats['p95'], $unit),
            $this->fmt($stats['max'], $unit),
            $this->trendLabel($stats['trend'])
        );
        $this->line($line);

        if ($excluded > 0) {
            $this->line("  ($excluded run(s) excluded: metric not recorded)");
        }
    }

    /**
     * @param array{job: ?string, metric: string, since: ?string, last: ?int, format: string} $options
     * @param float[] $values
     * @param array{min: float, p50: float, p95: float, max: float, trend: array{direction: string, percent: float|null, window: int}|null} $stats
     */
    private function renderJson(string $flow, array $options, array $values, int $excluded, array $stats, string $sparkline): string
    {
        $data = [
            'flow'      => $flow,
            'job'       => $options['job'],
            'metric'    => $options['metric'],
            'unit'      => self::UNITS[$options['metric']],
            'count'     => count($values),
            'excluded'  => $excluded,
            'values'    => $values,
            'min'       => $stats['min'],
            'p50'       => $stats['p50'],
            'p95'       => $stats['p95'],
            'max'       => $stats['max'],
            'trend'     => $stats['trend'],
            'sparkline' => $sparkline,
        ];
        return strval(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function fmt(float $value, string $unit): string
    {
        $rounded = $unit === 'cores' ? (string) (int) round($value) : (string) round($value, 1);
        return $unit === 'cores' ? "$rounded $unit" : "$rounded$unit";
    }

    /**
     * @param array{direction: string, percent: float|null, window: int}|null $trend
     */
    private function trendLabel(?array $trend): string
    {
        if ($trend === null) {
            return 'n/a';
        }
        $arrow = ['up' => '↑', 'down' => '↓', 'flat' => '→'][$trend['direction']] ?? '→';
        $magnitude = $trend['percent'] !== null ? sprintf(' %+.1f%%', $trend['percent']) : '';
        return "$arrow$magnitude vs prev {$trend['window']}";
    }
}
