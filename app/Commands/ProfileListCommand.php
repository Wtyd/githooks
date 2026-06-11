<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\App\Commands;

use LaravelZero\Framework\Commands\Command;
use Wtyd\GitHooks\History\RunHistoryReader;
use Wtyd\GitHooks\History\RunRecord;

/**
 * `profile:list <flow>` — list the runs persisted under `.githooks/history/`
 * for a flow (FEAT-5): timestamp, total time and pass/fail counts per run.
 */
class ProfileListCommand extends Command
{
    protected $signature = 'profile:list
                            {flow : The flow whose run history to list}
                            {--format= : Output format (text, json)}';

    protected $description = 'List the persisted run history for a flow';

    private RunHistoryReader $reader;

    public function __construct(RunHistoryReader $reader)
    {
        parent::__construct();
        $this->reader = $reader;
    }

    public function handle(): int
    {
        $flow = strval($this->argument('flow'));
        $format = $this->option('format') !== null ? strval($this->option('format')) : 'text';
        if (!in_array($format, ['text', 'json'], true)) {
            $this->error("Invalid --format '$format'. Valid formats: text, json.");
            return 1;
        }

        $runs = $this->reader->listRuns($flow);

        if ($format === 'json') {
            $this->line(strval(json_encode($this->toArray($flow, $runs), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)));
            return 0;
        }

        if ($runs === []) {
            $this->info("No run history for flow '$flow'.");
            return 0;
        }

        $rows = [];
        foreach ($runs as $run) {
            $rows[] = [$run->getTimestampLabel(), $run->getTotalTime(), $run->getPassed(), $run->getFailed()];
        }
        $this->table(['Timestamp', 'Total time', 'Passed', 'Failed'], $rows);

        return 0;
    }

    /**
     * @param RunRecord[] $runs
     * @return array<string, mixed>
     */
    private function toArray(string $flow, array $runs): array
    {
        $items = [];
        foreach ($runs as $run) {
            $items[] = [
                'timestamp' => $run->getTimestampLabel(),
                'totalTime' => $run->getTotalTime(),
                'passed'    => $run->getPassed(),
                'failed'    => $run->getFailed(),
                'skipped'   => $run->getSkipped(),
                'file'      => $run->getSourceFile(),
            ];
        }
        return ['flow' => $flow, 'count' => count($items), 'runs' => $items];
    }
}
