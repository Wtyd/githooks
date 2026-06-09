<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Output\OutputInterface;
use Wtyd\GitHooks\Execution\FlowResult;
use Wtyd\GitHooks\Execution\JobResult;
use Wtyd\GitHooks\Execution\Memory\MemoryStats;

/**
 * Renders the canonical 5-column `--stats` summary table after the flow
 * Results line (REQ-036), plus the temporal attribution lines for
 * memory and cores peaks (REQ-037).
 *
 *   +----------+--------+--------+------------+-------------+
 *   | Job      | Status | Time   | Peak Cores | Peak Memory |
 *   +----------+--------+--------+------------+-------------+
 *   | phpcs    | OK     | 0.4s   | 1          | 245 MB      |
 *   | ...      |        |        |            |             |
 *   +----------+--------+--------+------------+-------------+
 *   | TOTAL    | 5/5 ✔  | 21.6s  | 8/10       | 5410 MB     |
 *   +----------+--------+--------+------------+-------------+
 *
 *   Memory peak at 12.3s: phpstan 1920 + phpunit 1240
 *   Cores peak at 4.5s:   phpstan + phpunit + phpcs
 *
 * The memory column shows "n/a" when the sampler was not available
 * (graceful degradation on non-Linux).
 */
final class StatsTableRenderer
{
    /** Time warn (warn-after). U+FE0E forces the text presentation: monochrome, single-width. */
    private const ICON_TIME = "\u{23F1}\u{FE0E}";

    /** Memory warn (warn-above). U+25A4 is text by default — no variation selector needed. */
    private const ICON_MEMORY = "\u{25A4}";

    /** Cores over-subscription on the TOTAL row. U+FE0E forces the text presentation. */
    private const ICON_CORES = "\u{2699}\u{FE0E}";

    public function render(OutputInterface $output, FlowResult $result, string $sortMode = RenderOptions::STATS_SORT_EXEC): void
    {
        $stats = $result->getMemoryStats();
        if ($stats === null) {
            return;
        }

        $output->writeln('');
        $this->renderTable($output, $result, $stats, $sortMode);
        $this->renderAttribution($output, $stats);
    }

    private function renderTable(OutputInterface $output, FlowResult $result, MemoryStats $stats, string $sortMode): void
    {
        // FEAT-4: by default the table follows completion order (exec). With
        // name/type it is reordered for scannability and a leading `#` column
        // keeps the execution order visible.
        $showOrder = $sortMode !== RenderOptions::STATS_SORT_EXEC;
        $rows = $this->orderedRows($result->getJobResults(), $sortMode);

        $table = new Table($output);
        $headers = ['Job', 'Status', 'Time', 'Peak Cores', 'Peak Memory'];
        $table->setHeaders($showOrder ? array_merge(['#'], $headers) : $headers);

        foreach ($rows as $entry) {
            $job = $entry['job'];
            $cells = [
                $job->getJobName(),
                $this->renderJobStatus($job),
                $this->renderJobTime($job),
                $this->renderJobCores($job, $stats),
                $this->renderJobMemory($job, $stats),
            ];
            $table->addRow($showOrder ? array_merge([(string) $entry['order']], $cells) : $cells);
        }

        $table->addRow(new TableSeparator());
        $passed = $result->getPassedCount();
        $total = count($result->getJobResults());
        $isOk = $result->isSuccess();
        $totalCell = $isOk
            ? "<fg=green>$passed/$total ✔</>"
            : "<fg=red>$passed/$total ✗</>";
        $totalCells = [
            'TOTAL (flow)',
            $totalCell,
            $result->getTotalTime(),
            $this->renderTotalCores($stats),
            $stats->isSamplerActive() ? $stats->getMemoryPeak() . ' MB' : 'n/a',
        ];
        $table->addRow($showOrder ? array_merge(['-'], $totalCells) : $totalCells);

        $table->render();
    }

    /**
     * Pair each job with its 1-based execution order (its position in the
     * result list) and, for name/type sorts, reorder a copy without losing
     * that order. The execution-order tie-break keeps the result deterministic
     * on PHP 7.4, whose usort is not stable.
     *
     * @param JobResult[] $jobResults
     * @return array<int, array{order: int, job: JobResult}>
     */
    private function orderedRows(array $jobResults, string $sortMode): array
    {
        $rows = [];
        foreach (array_values($jobResults) as $index => $job) {
            $rows[] = ['order' => $index + 1, 'job' => $job];
        }

        if ($sortMode === RenderOptions::STATS_SORT_NAME) {
            usort($rows, function (array $left, array $right): int {
                return [$left['job']->getJobName(), $left['order']] <=> [$right['job']->getJobName(), $right['order']];
            });
        } elseif ($sortMode === RenderOptions::STATS_SORT_TYPE) {
            usort($rows, function (array $left, array $right): int {
                return [$left['job']->getType(), $left['job']->getJobName(), $left['order']]
                    <=> [$right['job']->getType(), $right['job']->getJobName(), $right['order']];
            });
        }

        return $rows;
    }

    /**
     * Symfony Console wraps `<fg=...>...</>` to ANSI when the OutputInterface
     * is decorated. FormatsOutput::forceCIDecorationIfApplicable() turns it on
     * for GitHub Actions / GitLab CI; off-TTY without CI the tags strip out
     * cleanly, so we never leak literal `<fg=...>` markers to plain logs.
     */
    private function renderJobStatus(JobResult $job): string
    {
        if ($job->isSkipped()) {
            return '<fg=blue>⏭</>';
        }
        if ($job->isMemoryFailed() || !$job->isSuccess()) {
            return '<fg=red>KO</>';
        }
        $icons = $this->warnIcons($job);
        if ($icons !== '') {
            return "<fg=yellow>OK $icons</>";
        }
        return 'OK';
    }

    /**
     * Combinable warn icons for the Status column, in a fixed order: time (⏱)
     * before memory (▤). These are the only two dimensions with a per-job warn
     * state in the model. Empty string when the job crossed no warn threshold.
     */
    private function warnIcons(JobResult $job): string
    {
        $icons = '';
        if ($job->isThresholdWarned()) {
            $icons .= self::ICON_TIME;
        }
        if ($job->isMemoryWarned()) {
            $icons .= self::ICON_MEMORY;
        }
        return $icons;
    }

    /**
     * TOTAL-row Peak Cores cell: `peak/limit`, marked with ⚙ in yellow only on
     * real over-subscription (coresPeak > coresLimit) — a job declared more
     * cores than the budget and ProcessPool admitted it. Saturation
     * (peak == limit) is exploited parallelism, not a warn, so it stays plain.
     */
    private function renderTotalCores(MemoryStats $stats): string
    {
        $cell = $stats->getCoresPeak() . '/' . $stats->getCoresLimit();
        if ($stats->getCoresPeak() > $stats->getCoresLimit()) {
            return '<fg=yellow>' . $cell . ' ' . self::ICON_CORES . '</>';
        }
        return $cell;
    }

    private function renderJobTime(JobResult $job): string
    {
        $time = $job->getExecutionTime();
        return $time !== '' ? $time : '-';
    }

    private function renderJobCores(JobResult $job, MemoryStats $stats): string
    {
        if ($job->isSkipped()) {
            return '-';
        }
        $cores = $stats->getJobCores($job->getJobName());
        return $cores !== null ? (string) $cores : '-';
    }

    private function renderJobMemory(JobResult $job, MemoryStats $stats): string
    {
        if (!$stats->isSamplerActive()) {
            return 'n/a';
        }
        $peak = $job->getMemoryPeak();
        if ($peak === null) {
            return '-';
        }
        return $peak . ' MB';
    }

    private function renderAttribution(OutputInterface $output, MemoryStats $stats): void
    {
        if ($stats->isSamplerActive()) {
            $output->writeln('');
            $output->writeln(sprintf(
                'Memory peak at %.2fs: %s',
                $stats->getMemoryPeakAtSecond(),
                $this->formatAttributionMap($stats->getMemoryPeakAttribution())
            ));
        }
        $coresAttribution = $stats->getCoresPeakJobs();
        if (!empty($coresAttribution)) {
            $output->writeln(sprintf(
                'Cores peak at %.2fs:  %s',
                $stats->getCoresPeakAtSecond(),
                implode(' + ', $coresAttribution)
            ));
        }
    }

    /**
     * @param array<string, int> $map
     */
    private function formatAttributionMap(array $map): string
    {
        if (empty($map)) {
            return '(no jobs in flight)';
        }
        $parts = [];
        foreach ($map as $name => $value) {
            $parts[] = "$name $value";
        }
        return implode(' + ', $parts);
    }
}
