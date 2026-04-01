<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\App\Commands\Concerns;

use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowResult;
use Wtyd\GitHooks\Output\JsonResultFormatter;
use Wtyd\GitHooks\Output\JunitResultFormatter;
use Wtyd\GitHooks\Output\NullOutputHandler;

/**
 * Shared logic for commands that support --format=text|json|junit.
 */
trait FormatsOutput
{
    private function applyFormat(FlowExecutor $executor): void
    {
        $format = strval($this->option('format'));
        if ($format === 'json' || $format === 'junit') {
            $executor->setOutputHandler(new NullOutputHandler());
        }
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
            $this->line("Results: $passed/$total passed" . ($result->isSuccess() ? ' ✔️' : ''));
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
