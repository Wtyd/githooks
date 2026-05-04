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
    public function render(OutputInterface $output, FlowResult $result): void
    {
        $stats = $result->getMemoryStats();
        if ($stats === null) {
            return;
        }

        $output->writeln('');
        $this->renderTable($output, $result, $stats);
        $this->renderAttribution($output, $stats);
    }

    private function renderTable(OutputInterface $output, FlowResult $result, MemoryStats $stats): void
    {
        $table = new Table($output);
        $table->setHeaders(['Job', 'Status', 'Time', 'Peak Cores', 'Peak Memory']);

        $coresLimit = $stats->getCoresLimit();

        foreach ($result->getJobResults() as $job) {
            $table->addRow([
                $job->getJobName(),
                $this->renderJobStatus($job),
                $this->renderJobTime($job),
                $this->renderJobCores($job, $stats),
                $this->renderJobMemory($job, $stats),
            ]);
        }

        $table->addRow(new TableSeparator());
        $passed = $result->getPassedCount();
        $total = count($result->getJobResults());
        $isOk = $result->isSuccess();
        $totalCell = $isOk
            ? "<fg=green>$passed/$total ✔</>"
            : "<fg=red>$passed/$total ✗</>";
        $table->addRow([
            'TOTAL (flow)',
            $totalCell,
            $result->getTotalTime(),
            $stats->getCoresPeak() . '/' . $coresLimit,
            $stats->isSamplerActive() ? $stats->getMemoryPeak() . ' MB' : 'n/a',
        ]);

        $table->render();
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
        if ($job->isMemoryWarned()) {
            return '<fg=yellow>OK ⚠</>';
        }
        return 'OK';
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
