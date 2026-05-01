<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\App\Commands\Concerns;

use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowPlan;
use Wtyd\GitHooks\Execution\FlowResult;
use Wtyd\GitHooks\Output\CI\CIEnvironment;
use Wtyd\GitHooks\Output\CI\GitHubActionsDecorator;
use Wtyd\GitHooks\Output\CI\GitLabCIDecorator;
use Wtyd\GitHooks\Output\DashboardOutputHandler;
use Wtyd\GitHooks\Output\CodeClimateResultFormatter;
use Wtyd\GitHooks\Output\JsonResultFormatter;
use Wtyd\GitHooks\Output\JunitResultFormatter;
use Wtyd\GitHooks\Output\OutputFormats;
use Wtyd\GitHooks\Output\OutputHandler;
use Wtyd\GitHooks\Output\ProgressOutputHandler;
use Wtyd\GitHooks\Output\ResultFormatter;
use Wtyd\GitHooks\Output\SarifResultFormatter;
use Wtyd\GitHooks\Output\StreamingTextOutputHandler;
use Wtyd\GitHooks\Utils\Printer;

/**
 * Shared logic for commands that support --format and multi-report --report-* flags.
 */
trait FormatsOutput
{
    /**
     * Select the output handler based on format and execution context.
     *
     * | Format | Sequential (processes<=1) | Parallel (processes>1) |
     * |--------|--------------------------|----------------------|
     * | text   | StreamingTextOutputHandler| TextOutputHandler    |
     * | json   | ProgressOutputHandler    | ProgressOutputHandler|
     * | junit  | ProgressOutputHandler    | ProgressOutputHandler|
     */
    private function applyFormat(FlowExecutor $executor, ?FlowPlan $plan = null): void
    {
        $format = strval($this->option('format'));

        if ($format !== '' && !in_array($format, OutputFormats::SUPPORTED, true)) {
            $this->warn("Unknown format '$format'. Using text output. Valid formats: " . implode(', ', OutputFormats::SUPPORTED) . '.');
            $format = 'text';
        }

        $isStructured = in_array($format, OutputFormats::STRUCTURED, true);

        if ($isStructured) {
            $handler = $this->resolveProgressHandler();
            if ($format === 'codeclimate' || $format === 'sarif') {
                $executor->setStructuredFormat(true);
            }
        } elseif (
            $plan === null
            || $plan->getOptions()->getProcesses() <= 1
            || count($plan->getJobs()) <= 1
        ) {
            // Text format: use streaming for sequential, keep buffered for parallel
            $handler = new StreamingTextOutputHandler($this->getLaravel()->make(Printer::class));
        } else {
            // Parallel text: use dashboard with live timers
            $handler = new DashboardOutputHandler();
        }

        // CI decorator only for text format — structured formats write clean output
        if (!$isStructured) {
            $handler = $this->wrapWithCIDecorator($handler);
        }
        $executor->setOutputHandler($handler);
    }

    /**
     * Wrap handler with CI decorator if auto-detected and not disabled.
     */
    private function wrapWithCIDecorator(OutputHandler $handler): OutputHandler
    {
        if ($this->isCIDisabled()) {
            return $handler;
        }

        $ci = CIEnvironment::detect();

        if ($ci === CIEnvironment::GITHUB_ACTIONS) {
            return new GitHubActionsDecorator($handler);
        }

        if ($ci === CIEnvironment::GITLAB_CI) {
            return new GitLabCIDecorator($handler);
        }

        return $handler;
    }

    private function isCIDisabled(): bool
    {
        return method_exists($this, 'option') && $this->hasOption('no-ci') && $this->option('no-ci');
    }

    /**
     * Resolve the progress handler for structured formats.
     * Uses the container binding so tests can override with NullOutputHandler.
     *
     * `--show-progress` forces progress to be emitted even when stderr is not a TTY
     * (useful in CI with --format=json|junit|sarif|codeclimate).
     */
    private function resolveProgressHandler(): ProgressOutputHandler
    {
        if ($this->getLaravel()->bound(ProgressOutputHandler::class)) {
            return $this->getLaravel()->make(ProgressOutputHandler::class);
        }

        $forceEnabled = $this->hasOption('show-progress') && (bool) $this->option('show-progress');
        return new ProgressOutputHandler(null, $forceEnabled);
    }

    /**
     * Render the FlowResult according to --format and emit any extra report files
     * declared via `--report-*` flags or the `reports` config map.
     */
    private function renderFormattedResult(FlowResult $result, ?OptionsConfiguration $options = null): void
    {
        $format = strval($this->option('format'));

        if (in_array($format, OutputFormats::STRUCTURED, true)) {
            $this->writeStructuredPayload($this->formatterFor($format)->format($result));
        } elseif ($format === '' || $format === 'text') {
            $this->renderTextSummary($result);
        } else {
            // Unknown format slipped through (e.g. raw test double bypassing applyFormat).
            $this->renderTextSummary($result);
        }

        $targets = $this->collectReportTargets($options);
        foreach ($targets as $reportFormat => $path) {
            $this->writeReportFile($reportFormat, $path, $result);
        }
    }

    /**
     * Render the human-readable summary (`Results: P/N passed in T`) plus the
     * per-job and flow-level threshold explanation lines (REQ-018..REQ-020).
     */
    private function renderTextSummary(FlowResult $result): void
    {
        $total = count($result->getJobResults());
        $passed = $result->getPassedCount();
        $time = $result->getTotalTime();

        $tbState = $result->getTimeBudgetState();
        $mbState = $result->getMemoryBudgetState();
        $suffix = '';
        if (!$result->isSuccess()) {
            $suffix = ' <fg=red>✗</>';
        } elseif (($tbState !== null && $tbState->isWarned()) || ($mbState !== null && $mbState->isWarned())) {
            $suffix = ' <fg=yellow>⚠ (1 warning)</>';
        } elseif ($result->isSuccess()) {
            $suffix = ' ✔️';
        }

        $this->line("Results: $passed/$total passed in $time$suffix");

        // Per-job threshold notices, ordered by appearance.
        foreach ($result->getJobResults() as $jobResult) {
            $this->emitJobThresholdNotice($jobResult);
            $this->emitJobMemoryNotice($jobResult);
        }

        // Flow-level time-budget notice (last so it summarises).
        if ($tbState !== null && $tbState->isFailed()) {
            $limit = $tbState->getFailAfter();
            $sum = number_format($tbState->getTotalJobDuration(), 1);
            $this->line("<fg=red>✗ Flow time-budget exceeded: total job time {$sum}s, limit {$limit}s</>");
        } elseif ($tbState !== null && $tbState->isWarned()) {
            $limit = $tbState->getWarnAfter();
            $sum = number_format($tbState->getTotalJobDuration(), 1);
            $this->line("<fg=yellow>⚠ Flow time-budget warning: total job time {$sum}s exceeded warn-after ({$limit}s)</>");
        }

        $this->emitFlowMemoryBudgetNotice($mbState);

        if ($result->getMemoryStats() !== null) {
            (new \Wtyd\GitHooks\Output\StatsTableRenderer())
                ->render($this->getOutput(), $result);
        }
    }

    private function emitJobMemoryNotice(\Wtyd\GitHooks\Execution\JobResult $jobResult): void
    {
        if ($jobResult->getMemoryThresholdState() === \Wtyd\GitHooks\Execution\JobResult::MEMORY_THRESHOLD_NONE) {
            return;
        }

        $name = $jobResult->getJobName();
        $peak = $jobResult->getMemoryPeak();
        $isFailed = $jobResult->isMemoryFailed();
        $limit = $isFailed ? $jobResult->getConfiguredMemoryFail() : $jobResult->getConfiguredMemoryWarn();
        $kind = $isFailed ? 'fail-above' : 'warn-above';

        if (!$jobResult->isSuccess() && $jobResult->getExitCode() !== null && $jobResult->getExitCode() !== 0) {
            $this->line("   <fg=yellow>↳ also exceeded memory threshold (peak {$peak} MB, $kind {$limit} MB)</>");
            return;
        }

        $color = $isFailed ? 'red' : 'yellow';
        $icon = $isFailed ? '✗' : '⚠';
        $this->line("<fg=$color>$icon Job '$name' exceeded memory threshold (peak {$peak} MB, $kind {$limit} MB)</>");
    }

    private function emitFlowMemoryBudgetNotice(?\Wtyd\GitHooks\Execution\MemoryBudgetState $state): void
    {
        if ($state === null) {
            return;
        }
        if ($state->isFailed()) {
            $limit = $state->getFailAbove();
            $peak = $state->getPeakObserved();
            $this->line("<fg=red>✗ Flow memory-budget exceeded: peak {$peak} MB, limit {$limit} MB</>");
        } elseif ($state->isWarned()) {
            $limit = $state->getWarnAbove();
            $peak = $state->getPeakObserved();
            $this->line("<fg=yellow>⚠ Flow memory-budget warning: peak {$peak} MB exceeded warn-above ({$limit} MB)</>");
        }
    }

    private function emitJobThresholdNotice(\Wtyd\GitHooks\Execution\JobResult $jobResult): void
    {
        if ($jobResult->getThresholdState() === \Wtyd\GitHooks\Execution\JobResult::THRESHOLD_NONE) {
            return;
        }

        $name = $jobResult->getJobName();
        $duration = number_format($jobResult->getDurationSeconds(), 1);
        $isFailed = $jobResult->isThresholdFailed();
        $limit = $isFailed ? $jobResult->getConfiguredFailAfter() : $jobResult->getConfiguredWarnAfter();
        $kind = $isFailed ? 'fail-after' : 'warn-after';

        // Real KO with secondary threshold annotation: indented secondary line.
        if (!$jobResult->isSuccess() && $jobResult->getExitCode() !== null && $jobResult->getExitCode() !== 0) {
            $this->line("   <fg=yellow>↳ also exceeded time threshold (took {$duration}s, $kind {$limit}s)</>");
            return;
        }

        $color = $isFailed ? 'red' : 'yellow';
        $icon = $isFailed ? '✗' : '⚠';
        $this->line("<fg=$color>$icon Job '$name' exceeded time threshold (took {$duration}s, $kind {$limit}s)</>");
    }

    /**
     * Build the [format => path] map of extra report files to emit.
     *
     * Precedence (per format):
     *   1. CLI flag `--report-X=PATH` (always wins).
     *   2. `flow.options.reports.X` / `flows.options.reports.X` from config,
     *      unless `--no-reports` is active.
     *
     * `--no-reports` short‑circuits config but does NOT cancel CLI flags
     * (PHPUnit `--no-coverage` style), so a consumer can do:
     *   `flow qa --format=json --no-reports --report-sarif=/tmp/q.sarif`
     * to get JSON on stdout and only the SARIF file, ignoring whatever
     * the project config declares.
     *
     * @return array<string, string>
     */
    private function collectReportTargets(?OptionsConfiguration $options): array
    {
        $noReports = $this->hasOption('no-reports') && (bool) $this->option('no-reports');

        $targets = ($noReports || $options === null) ? [] : $options->getReports();

        foreach (OutputFormats::STRUCTURED as $format) {
            $cliKey = "report-$format";
            if (!$this->hasOption($cliKey)) {
                continue;
            }
            $value = $this->option($cliKey);
            if ($value === null || $value === '') {
                continue;
            }
            $targets[$format] = strval($value);
        }

        return $targets;
    }

    /**
     * Render and write a single report file for the given format.
     */
    private function writeReportFile(string $format, string $path, FlowResult $result): void
    {
        $content = $this->formatterFor($format)->format($result);
        $this->writeContentToFile($content, $path);
        $this->emitReportWrittenNotice($path);
    }

    /**
     * Map a structured format name to its concrete ResultFormatter.
     *
     * @throws \InvalidArgumentException for unsupported formats.
     */
    private function formatterFor(string $format): ResultFormatter
    {
        switch ($format) {
            case 'json':
                return new JsonResultFormatter();
            case 'junit':
                return new JunitResultFormatter();
            case 'sarif':
                return new SarifResultFormatter();
            case 'codeclimate':
                return new CodeClimateResultFormatter();
            default:
                throw new \InvalidArgumentException("Unsupported report format: '$format'");
        }
    }

    /**
     * Write a structured payload to stdout (default) or to a file when --output=PATH is set.
     * Missing parent directories in the target path are created on the fly so that
     * CI pipelines can use `--output=reports/qa.sarif` without a preceding `mkdir`.
     */
    private function writeStructuredPayload(string $content): void
    {
        $customOutput = $this->hasOption('output') ? $this->option('output') : null;

        if (empty($customOutput)) {
            $this->line($content);
            return;
        }

        $path = strval($customOutput);
        $this->writeContentToFile($content, $path);
        $this->emitReportWrittenNotice($path);
    }

    /**
     * Inform the operator that a report file was written. Goes to STDERR so
     * --format=json/junit/sarif/codeclimate stdout stays a clean parseable
     * payload (BUG-5). Protected so test doubles can capture the calls.
     */
    protected function emitReportWrittenNotice(string $path): void
    {
        fwrite(STDERR, "Report written to: $path\n");
    }

    /**
     * Write content to a file, creating any missing parent directories.
     */
    private function writeContentToFile(string $content, string $path): void
    {
        $dir = dirname($path);
        if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($path, $content . "\n");
    }

    private function renderMonitorReport(FlowResult $result): void
    {
        $peak = $result->getPeakEstimatedThreads();
        $budget = $result->getThreadBudget();

        if ($peak === 0 && $budget === 0) {
            return;
        }

        $this->line('');
        $this->line("Thread monitor: peak ~{$peak} threads (budget: {$budget})");

        if ($peak > $budget && $budget > 0) {
            $this->warn("  Warning: estimated peak ($peak) exceeded budget ($budget). Consider reducing 'processes' or tool parallelism.");
        }
    }
}
