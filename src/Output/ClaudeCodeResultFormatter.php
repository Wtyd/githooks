<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output;

use Wtyd\GitHooks\Execution\FlowResult;
use Wtyd\GitHooks\Execution\JobResult;
use Wtyd\GitHooks\Execution\MemoryBudgetState;
use Wtyd\GitHooks\Execution\TimeBudgetState;
use Wtyd\GitHooks\Output\Concerns\StripsAnsi;

/**
 * Renders a {@see FlowResult} as the Claude Code stop-hook protocol (FEAT-15).
 *
 * Contract:
 *  - Success → empty string. The hook stays silent and the agent is not blocked.
 *  - Failure → `{"decision":"block","reason":"## job\n<output>\n\n## job2\n…"}`.
 *
 * The `reason` aggregates the plain-text output of every failed (non-skipped)
 * job under a Markdown `## <jobName>` heading. ANSI escapes are stripped and
 * `json_encode` escapes quotes/newlines/tabs, so the payload is always a single
 * parseable JSON line. When the flow fails purely on a time/memory budget (every
 * job passed), the reason carries the budget explanation instead.
 *
 * @see OutputFormats::exitCodeFor() for why this format always exits 0.
 */
class ClaudeCodeResultFormatter implements ResultFormatter
{
    use StripsAnsi;

    public function format(FlowResult $result): string
    {
        if ($result->isSuccess()) {
            return '';
        }

        $sections = $this->failedJobSections($result);

        if ($sections === []) {
            // All jobs passed but the flow still failed → a budget overrun.
            $budget = $this->budgetReason($result);
            if ($budget !== '') {
                $sections[] = $budget;
            }
        }

        $payload = [
            'decision' => 'block',
            'reason'   => implode("\n\n", $sections),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json !== false
            ? $json
            : '{"decision":"block","reason":"githooks: JSON encoding failed"}';
    }

    /**
     * One `## <jobName>\n<output>` section per failed, non-skipped job, in run order.
     *
     * @return string[]
     */
    private function failedJobSections(FlowResult $result): array
    {
        $sections = [];
        foreach ($result->getJobResults() as $job) {
            if ($job->isSuccess() || $job->isSkipped()) {
                continue;
            }
            $sections[] = $this->section($job);
        }

        return $sections;
    }

    private function section(JobResult $job): string
    {
        $output = trim($this->stripAnsi($job->getOutput()));

        return "## {$job->getJobName()}\n{$output}";
    }

    /**
     * Human-readable reason for a flow that failed solely on a budget (no job KO).
     */
    private function budgetReason(FlowResult $result): string
    {
        $reasons = [];

        $time = $result->getTimeBudgetState();
        if ($time !== null && $time->isFailed()) {
            $reasons[] = $this->timeBudgetReason($time);
        }

        $memory = $result->getMemoryBudgetState();
        if ($memory !== null && $memory->isFailed()) {
            $reasons[] = $this->memoryBudgetReason($memory);
        }

        return implode("\n", $reasons);
    }

    private function timeBudgetReason(TimeBudgetState $state): string
    {
        $total = number_format($state->getTotalJobDuration(), 1);

        return "Flow time-budget exceeded: total job time {$total}s, limit {$state->getFailAfter()}s";
    }

    private function memoryBudgetReason(MemoryBudgetState $state): string
    {
        return "Flow memory-budget exceeded: peak {$state->getPeakObserved()} MB, limit {$state->getFailAbove()} MB";
    }
}
