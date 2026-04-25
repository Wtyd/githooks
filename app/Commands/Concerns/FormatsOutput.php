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
            $total = count($result->getJobResults());
            $passed = $result->getPassedCount();
            $time = $result->getTotalTime();
            $this->line("Results: $passed/$total passed in $time" . ($result->isSuccess() ? ' ✔️' : ''));
        } else {
            // Unknown format slipped through (e.g. raw test double bypassing applyFormat).
            $total = count($result->getJobResults());
            $passed = $result->getPassedCount();
            $time = $result->getTotalTime();
            $this->line("Results: $passed/$total passed in $time" . ($result->isSuccess() ? ' ✔️' : ''));
        }

        $targets = $this->collectReportTargets($options);
        foreach ($targets as $reportFormat => $path) {
            $this->writeReportFile($reportFormat, $path, $result);
        }
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
        $this->info("Report written to: $path");
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
        $this->info("Report written to: $path");
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
