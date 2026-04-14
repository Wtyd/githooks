<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\App\Commands\Concerns;

use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowPlan;
use Wtyd\GitHooks\Execution\FlowResult;
use Wtyd\GitHooks\Output\JsonResultFormatter;
use Wtyd\GitHooks\Output\JunitResultFormatter;
use Wtyd\GitHooks\Output\ProgressOutputHandler;
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

        if ($format !== '' && !in_array($format, ['text', 'json', 'junit'], true)) {
            $this->warn("Unknown format '$format'. Using text output. Valid formats: text, json, junit.");
            $format = 'text';
        }

        if ($format === 'json' || $format === 'junit') {
            $executor->setOutputHandler(new ProgressOutputHandler());
            return;
        }

        // Text format: use streaming for sequential, keep buffered for parallel
        $isSequential = $plan === null
            || $plan->getOptions()->getProcesses() <= 1
            || count($plan->getJobs()) <= 1;

        if ($isSequential) {
            $executor->setOutputHandler(
                new StreamingTextOutputHandler($this->getLaravel()->make(Printer::class))
            );
        }
        // else: keep default TextOutputHandler (parallel, buffered)
    }

    private function renderFormattedResult(FlowResult $result): void
    {
        $format = strval($this->option('format'));

        if ($format === 'json') {
            $this->line((new JsonResultFormatter())->format($result));
        } elseif ($format === 'junit') {
            $this->line((new JunitResultFormatter())->format($result));
        } else {
            $total = count($result->getJobResults());
            $passed = $result->getPassedCount();
            $time = $result->getTotalTime();
            $this->line("Results: $passed/$total passed in $time" . ($result->isSuccess() ? ' ✔️' : ''));
        }
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
