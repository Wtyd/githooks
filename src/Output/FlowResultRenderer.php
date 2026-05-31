<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output;

use Illuminate\Container\Container;
use InvalidArgumentException;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\OutputStyle as SymfonyOutputStyle;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowPlan;
use Wtyd\GitHooks\Execution\FlowResult;
use Wtyd\GitHooks\Execution\JobResult;
use Wtyd\GitHooks\Execution\MemoryBudgetState;
use Wtyd\GitHooks\Output\CI\CIEnvironment;
use Wtyd\GitHooks\Output\CI\GitHubActionsDecorator;
use Wtyd\GitHooks\Output\CI\GitLabCIDecorator;
use Wtyd\GitHooks\Utils\Printer;

/**
 * Render the FlowResult of a `flow`, `flows` or `job` run according to the
 * effective `--format` (text, json, junit, codeclimate, sarif) and emit any
 * extra `--report-*` files declared via CLI or the `reports` config map.
 *
 * Ported from `app/Commands/Concerns/FormatsOutput` (trait, 478 LoC) in
 * Phase 2a of the JobCommand/FlowCommand/FlowsCommand refactor. The trait
 * still exists as a thin adapter that delegates here, so the three commands
 * keep working untouched. Phase 2b/2c migrates each command to call this
 * class directly via the Runner.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Renders 4 structured formats + 3 text handlers
 *   + 2 CI decorators; the coupling reflects the surface, not a smell.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Inherits the surface of the 478 LoC FormatsOutput
 *   trait. Splitting the class would only move logic across files without reducing the conceptual
 *   surface and would break the 1:1 mapping with the legacy tests that are still alive in 2a.
 */
class FlowResultRenderer
{
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Select the output handler based on format and execution context.
     *
     * | Format | Sequential (processes<=1) | Parallel (processes>1) |
     * |--------|--------------------------|----------------------|
     * | text   | StreamingTextOutputHandler| TextOutputHandler    |
     * | json   | ProgressOutputHandler    | ProgressOutputHandler|
     * | junit  | ProgressOutputHandler    | ProgressOutputHandler|
     *
     * @param OutputInterface $output Either a Symfony OutputInterface or any object exposing
     *   `writeln(string)`. Loose typing during the Phase 2a adapter window so the
     *   existing trait test doubles keep working; tightened to `OutputInterface`
     *   in Phase 2c.
     */
    public function applyFormat(
        FlowExecutor $executor,
        ?FlowPlan $plan,
        RenderOptions $options,
        OutputInterface $output
    ): void {
        $this->forceCIDecorationIfApplicable($options, $output);

        $format = $options->format;

        if ($format !== '' && !in_array($format, OutputFormats::SUPPORTED, true)) {
            $this->writeWarn($output, "Unknown format '$format'. Using text output. Valid formats: " . implode(', ', OutputFormats::SUPPORTED) . '.');
            $format = 'text';
        }

        // claude-code is stdout-only like the structured formats: progress goes
        // to stderr and the final payload is the only thing on stdout, so it
        // shares their handler and skips the CI ANSI decorator (FEAT-15).
        $cleanStdout = OutputFormats::hasCleanStdout($format);

        if ($cleanStdout) {
            $handler = $this->resolveProgressHandler($options);
        } elseif (
            $plan === null
            || $plan->getOptions()->getProcesses() <= 1
            || count($plan->getJobs()) <= 1
        ) {
            // Text format: use streaming for sequential, keep buffered for parallel
            $handler = new StreamingTextOutputHandler($this->container->make(Printer::class));
        } else {
            // Parallel text: use dashboard with live timers
            $handler = new DashboardOutputHandler();
        }

        if ($this->needsToolJsonOutput($format, $plan, $options)) {
            $executor->setStructuredFormat(true);
        }

        // CI decorator only for text format — clean-stdout formats write raw output
        if (!$cleanStdout) {
            $handler = $this->wrapWithCIDecorator($handler, $options);
        }
        $executor->setOutputHandler($handler);
    }

    /**
     * Whether tool-level JSON output (e.g. phpstan `--error-format=json`,
     * psalm `--output-format=json`) must be requested at runtime. Codeclimate
     * and SARIF formatters parse each tool's stdout to extract issues, so the
     * flag must be on whenever a codeclimate or sarif payload will be
     * generated, regardless of how it was requested.
     */
    private function needsToolJsonOutput(string $format, ?FlowPlan $plan, RenderOptions $options): bool
    {
        if ($format === 'codeclimate' || $format === 'sarif') {
            return true;
        }
        $planOptions = $plan !== null ? $plan->getOptions() : null;
        $reports = $this->collectReportTargets($planOptions, $options);
        return isset($reports['codeclimate']) || isset($reports['sarif']);
    }

    /**
     * Wrap handler with CI decorator if auto-detected and not disabled.
     */
    private function wrapWithCIDecorator(OutputHandler $handler, RenderOptions $options): OutputHandler
    {
        if ($options->noCI) {
            return $handler;
        }

        $environment = CIEnvironment::detect();

        if ($environment === CIEnvironment::GITHUB_ACTIONS) {
            return new GitHubActionsDecorator($handler);
        }

        if ($environment === CIEnvironment::GITLAB_CI) {
            return new GitLabCIDecorator($handler);
        }

        return $handler;
    }

    /**
     * Force ANSI decoration on the OutputInterface when running under a CI
     * that renders ANSI escapes (GitHub Actions, GitLab CI). Symfony Console
     * disables decoration off-TTY by default, which strips every `<fg=red>…</>`
     * tag the renderer emits — making `✗ Flow time-budget exceeded` and
     * warning lines invisible in CI logs (the operator's eye has no signal to
     * lock on).
     *
     * @param OutputInterface $output See {@see applyFormat()} for the duck-typed contract.
     */
    private function forceCIDecorationIfApplicable(RenderOptions $options, OutputInterface $output): void
    {
        if ($options->noCI) {
            return;
        }
        if (CIEnvironment::detect() === CIEnvironment::NONE) {
            return;
        }
        $output->setDecorated(true);
    }

    /**
     * Open the GitLab CI "Summary" collapsible section (expanded by default) so
     * the final results table is its own group instead of being absorbed by
     * whichever per-job section happens to be the last open one.
     */
    private function emitCISummarySectionStart(RenderOptions $options): void
    {
        if ($options->noCI) {
            return;
        }
        if (CIEnvironment::detect() !== CIEnvironment::GITLAB_CI) {
            return;
        }
        $timestamp = time();
        echo "\033[0Ksection_start:{$timestamp}:githooks_summary[collapsed=false]\r\033[0KSummary\n";
    }

    private function emitCISummarySectionEnd(RenderOptions $options): void
    {
        if ($options->noCI) {
            return;
        }
        if (CIEnvironment::detect() !== CIEnvironment::GITLAB_CI) {
            return;
        }
        $timestamp = time();
        echo "\033[0Ksection_end:{$timestamp}:githooks_summary\r\033[0K\n";
    }

    /**
     * Resolve the progress handler for structured formats.
     * Uses the container binding so tests can override with NullOutputHandler.
     *
     * `--show-progress` forces progress to be emitted even when stderr is not a TTY
     * (useful in CI with --format=json|junit|sarif|codeclimate).
     */
    private function resolveProgressHandler(RenderOptions $options): ProgressOutputHandler
    {
        if ($this->container->bound(ProgressOutputHandler::class)) {
            return $this->container->make(ProgressOutputHandler::class);
        }

        return new ProgressOutputHandler(null, $options->showProgress);
    }

    /**
     * Render the FlowResult according to --format and emit any extra report
     * files declared via `--report-*` flags or the `reports` config map.
     *
     * @param OutputInterface $output See {@see applyFormat()} for the duck-typed contract.
     */
    public function renderFormattedResult(
        FlowResult $result,
        ?OptionsConfiguration $planOptions,
        RenderOptions $options,
        OutputInterface $output
    ): void {
        $format = $options->format;

        // FEAT-15: the AI stop-hook payload is stdout-only and never produces
        // report files — emit it (silent on success) and return early.
        if ($format === OutputFormats::CLAUDE_CODE) {
            $payload = (new ClaudeCodeResultFormatter())->format($result);
            if ($payload !== '') {
                $output->writeln($payload);
            }
            return;
        }

        if (in_array($format, OutputFormats::STRUCTURED, true)) {
            $this->writeStructuredPayload($this->formatterFor($format)->format($result), $options, $output);
        } else {
            $this->renderTextSummary($result, $options, $output);
        }

        $targets = $this->collectReportTargets($planOptions, $options);
        foreach ($targets as $reportFormat => $path) {
            $this->writeReportFile($reportFormat, $path, $result, $output);
        }
    }

    /**
     * Render the human-readable summary (`Results: P/N passed in T`) plus the
     * per-job and flow-level threshold explanation lines (REQ-018..REQ-020).
     *
     * @param OutputInterface $output
     */
    private function renderTextSummary(FlowResult $result, RenderOptions $options, OutputInterface $output): void
    {
        $this->emitCISummarySectionStart($options);
        try {
            $this->renderTextSummaryBody($result, $output);
        } finally {
            $this->emitCISummarySectionEnd($options);
        }
    }

    /**
     * @param OutputInterface $output
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Three independent decision points (result success,
     *   time-budget state, memory-budget state) drive the suffix and the per-job/flow notice blocks.
     *   Splitting would either inline the same branches in helpers or hide the linear flow of the
     *   summary, neither of which reads better than the current shape.
     */
    private function renderTextSummaryBody(FlowResult $result, OutputInterface $output): void
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

        $output->writeln("Results: $passed/$total passed in $time$suffix");

        // Per-job threshold notices, ordered by appearance.
        foreach ($result->getJobResults() as $jobResult) {
            $this->emitJobThresholdNotice($jobResult, $output);
            $this->emitJobMemoryNotice($jobResult, $output);
        }

        // Flow-level time-budget notice (last so it summarises).
        if ($tbState !== null && $tbState->isFailed()) {
            $limit = $tbState->getFailAfter();
            $sum = number_format($tbState->getTotalJobDuration(), 1);
            $output->writeln("<fg=red>✗ Flow time-budget exceeded: total job time {$sum}s, limit {$limit}s</>");
        } elseif ($tbState !== null && $tbState->isWarned()) {
            $limit = $tbState->getWarnAfter();
            $sum = number_format($tbState->getTotalJobDuration(), 1);
            $output->writeln("<fg=yellow>⚠ Flow time-budget warning: total job time {$sum}s exceeded warn-after ({$limit}s)</>");
        }

        $this->emitFlowMemoryBudgetNotice($mbState, $output);

        if ($result->getMemoryStats() !== null) {
            (new StatsTableRenderer())->render($output, $result);
        }
    }

    /**
     * @param OutputInterface $output
     */
    private function emitJobMemoryNotice(JobResult $jobResult, OutputInterface $output): void
    {
        if ($jobResult->getMemoryThresholdState() === JobResult::MEMORY_THRESHOLD_NONE) {
            return;
        }

        $name = $jobResult->getJobName();
        $peak = $jobResult->getMemoryPeak();
        $isFailed = $jobResult->isMemoryFailed();
        $limit = $isFailed ? $jobResult->getConfiguredMemoryFail() : $jobResult->getConfiguredMemoryWarn();
        $kind = $isFailed ? 'fail-above' : 'warn-above';

        if (!$jobResult->isSuccess() && $jobResult->getExitCode() !== null && $jobResult->getExitCode() !== 0) {
            $output->writeln("   <fg=yellow>↳ also exceeded memory threshold (peak {$peak} MB, $kind {$limit} MB)</>");
            return;
        }

        $color = $isFailed ? 'red' : 'yellow';
        $icon = $isFailed ? '✗' : '⚠';
        $output->writeln("<fg=$color>$icon Job '$name' exceeded memory threshold (peak {$peak} MB, $kind {$limit} MB)</>");
    }

    /**
     * @param OutputInterface $output
     */
    private function emitFlowMemoryBudgetNotice(?MemoryBudgetState $state, OutputInterface $output): void
    {
        if ($state === null) {
            return;
        }
        if ($state->isFailed()) {
            $limit = $state->getFailAbove();
            $peak = $state->getPeakObserved();
            $output->writeln("<fg=red>✗ Flow memory-budget exceeded: peak {$peak} MB, limit {$limit} MB</>");
        } elseif ($state->isWarned()) {
            $limit = $state->getWarnAbove();
            $peak = $state->getPeakObserved();
            $output->writeln("<fg=yellow>⚠ Flow memory-budget warning: peak {$peak} MB exceeded warn-above ({$limit} MB)</>");
        }
    }

    /**
     * @param OutputInterface $output
     */
    private function emitJobThresholdNotice(JobResult $jobResult, OutputInterface $output): void
    {
        if ($jobResult->getThresholdState() === JobResult::THRESHOLD_NONE) {
            return;
        }

        $name = $jobResult->getJobName();
        $duration = number_format($jobResult->getDurationSeconds(), 1);
        $isFailed = $jobResult->isThresholdFailed();
        $limit = $isFailed ? $jobResult->getConfiguredFailAfter() : $jobResult->getConfiguredWarnAfter();
        $kind = $isFailed ? 'fail-after' : 'warn-after';

        // Real KO with secondary threshold annotation: indented secondary line.
        if (!$jobResult->isSuccess() && $jobResult->getExitCode() !== null && $jobResult->getExitCode() !== 0) {
            $output->writeln("   <fg=yellow>↳ also exceeded time threshold (took {$duration}s, $kind {$limit}s)</>");
            return;
        }

        $color = $isFailed ? 'red' : 'yellow';
        $icon = $isFailed ? '✗' : '⚠';
        $output->writeln("<fg=$color>$icon Job '$name' exceeded time threshold (took {$duration}s, $kind {$limit}s)</>");
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
     * (PHPUnit `--no-coverage` style).
     *
     * @return array<string, string>
     */
    public function collectReportTargets(?OptionsConfiguration $planOptions, RenderOptions $options): array
    {
        $targets = ($options->noReports || $planOptions === null) ? [] : $planOptions->getReports();

        foreach (OutputFormats::STRUCTURED as $format) {
            if (!isset($options->cliReports[$format])) {
                continue;
            }
            $value = $options->cliReports[$format];
            if ($value === '') {
                continue;
            }
            $targets[$format] = $value;
        }

        return $targets;
    }

    /**
     * Render and write a single report file for the given format.
     *
     * @param OutputInterface $output
     */
    private function writeReportFile(string $format, string $path, FlowResult $result, OutputInterface $output): void
    {
        $content = $this->formatterFor($format)->format($result);
        $this->writeContentToFile($content, $path);
        $this->emitReportWrittenNotice($path, $output);
    }

    /**
     * Map a structured format name to its concrete ResultFormatter.
     *
     * @throws \InvalidArgumentException for unsupported formats.
     */
    public function formatterFor(string $format): ResultFormatter
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
                throw new InvalidArgumentException("Unsupported report format: '$format'");
        }
    }

    /**
     * Write a structured payload to stdout (default) or to a file when
     * `--output=PATH` is set. Missing parent directories in the target path
     * are created on the fly so that CI pipelines can use
     * `--output=reports/qa.sarif` without a preceding `mkdir`.
     *
     * @param OutputInterface $output
     */
    private function writeStructuredPayload(string $content, RenderOptions $options, OutputInterface $output): void
    {
        if ($options->outputPath === null || $options->outputPath === '') {
            $output->writeln($content);
            return;
        }

        $this->writeContentToFile($content, $options->outputPath);
        $this->emitReportWrittenNotice($options->outputPath, $output);
    }

    /**
     * Inform the operator that a report file was written. Goes to STDERR so
     * --format=json/junit/sarif/codeclimate stdout stays a clean parseable
     * payload (BUG-5).
     *
     * @param OutputInterface $output
     */
    private function emitReportWrittenNotice(string $path, OutputInterface $output): void
    {
        $message = "Report written to: $path";

        if ($output instanceof SymfonyOutputStyle) {
            $underlying = $this->extractUnderlyingOutput($output);
            if ($underlying instanceof ConsoleOutputInterface) {
                $underlying->getErrorOutput()->writeln($message);
            }
            return;
        }

        if ($output instanceof ConsoleOutputInterface) {
            $output->getErrorOutput()->writeln($message);
            return;
        }

        // Duck-typed test doubles: route to writeln so test sinks can capture
        // the notice (the production stdout/stderr split only applies when the
        // caller hands us a real ConsoleOutput-backed channel).
        $output->writeln($message);
    }

    /**
     * Extract the underlying Symfony OutputInterface from an OutputStyle. We
     * cannot call OutputStyle::getErrorOutput() directly because it is
     * protected; the Illuminate subclass exposes getOutput() publicly, which
     * gives us the wrapped output we can then ask for getErrorOutput().
     */
    private function extractUnderlyingOutput(SymfonyOutputStyle $output): ?OutputInterface
    {
        if (method_exists($output, 'getOutput')) {
            $underlying = $output->getOutput();
            return $underlying instanceof OutputInterface ? $underlying : null;
        }
        return null;
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

    /**
     * Render the thread monitor report (only invoked when `--monitor` is set).
     *
     * @param OutputInterface $output
     */
    public function renderMonitorReport(FlowResult $result, OutputInterface $output): void
    {
        $peak = $result->getPeakEstimatedThreads();
        $budget = $result->getThreadBudget();

        if ($peak === 0 && $budget === 0) {
            return;
        }

        $output->writeln('');
        $output->writeln("Thread monitor: peak ~{$peak} threads (budget: {$budget})");

        if ($peak > $budget && $budget > 0) {
            $this->writeWarn($output, "  Warning: estimated peak ($peak) exceeded budget ($budget). Consider reducing 'processes' or tool parallelism.");
        }
    }

    /**
     * Emit a `<comment>`-styled warning line, matching the Illuminate
     * Command::warn() output (yellow text via the 'comment' style).
     *
     * @param OutputInterface $output
     */
    private function writeWarn(OutputInterface $output, string $message): void
    {
        $output->writeln("<comment>$message</comment>");
    }
}
