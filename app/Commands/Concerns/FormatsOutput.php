<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\App\Commands\Concerns;

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
use Wtyd\GitHooks\Output\NullOutputHandler;
use Wtyd\GitHooks\Output\OutputHandler;
use Wtyd\GitHooks\Output\ProgressOutputHandler;
use Wtyd\GitHooks\Output\SarifResultFormatter;
use Wtyd\GitHooks\Output\StreamingTextOutputHandler;
use Wtyd\GitHooks\Utils\Printer;

/**
 * Shared logic for commands that support --format=text|json|junit.
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

        $validFormats = ['text', 'json', 'junit', 'codeclimate', 'sarif'];
        if ($format !== '' && !in_array($format, $validFormats, true)) {
            $this->warn("Unknown format '$format'. Using text output. Valid formats: " . implode(', ', $validFormats) . '.');
            $format = 'text';
        }

        $isStructured = in_array($format, ['json', 'junit', 'codeclimate', 'sarif'], true);

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
     * `-v` / --verbose forces progress to be emitted even when stderr is not a TTY.
     */
    private function resolveProgressHandler(): ProgressOutputHandler
    {
        if ($this->getLaravel()->bound(ProgressOutputHandler::class)) {
            return $this->getLaravel()->make(ProgressOutputHandler::class);
        }

        $forceEnabled = method_exists($this, 'getOutput') && $this->getOutput()->isVerbose();
        return new ProgressOutputHandler(null, $forceEnabled);
    }

    private function renderFormattedResult(FlowResult $result): void
    {
        $format = strval($this->option('format'));

        if ($format === 'json') {
            $this->writeStructuredPayload((new JsonResultFormatter())->format($result));
        } elseif ($format === 'junit') {
            $this->writeStructuredPayload((new JunitResultFormatter())->format($result));
        } elseif ($format === 'codeclimate') {
            $this->writeStructuredPayload((new CodeClimateResultFormatter())->format($result));
        } elseif ($format === 'sarif') {
            $this->writeStructuredPayload((new SarifResultFormatter())->format($result));
        } else {
            $total = count($result->getJobResults());
            $passed = $result->getPassedCount();
            $time = $result->getTotalTime();
            $this->line("Results: $passed/$total passed in $time" . ($result->isSuccess() ? ' ✔️' : ''));
        }
    }

    /**
     * Write a structured payload to stdout (default) or to a file when --output=PATH is set.
     */
    private function writeStructuredPayload(string $content): void
    {
        $customOutput = $this->hasOption('output') ? $this->option('output') : null;

        if (empty($customOutput)) {
            $this->line($content);
            return;
        }

        $path = strval($customOutput);
        file_put_contents($path, $content . "\n");
        $this->info("Report written to: $path");
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
