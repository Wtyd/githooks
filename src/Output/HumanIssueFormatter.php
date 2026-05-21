<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output;

use Wtyd\GitHooks\Output\Concerns\RelativizesFilePath;
use Wtyd\GitHooks\Output\ToolOutputParser\ToolOutputParserRegistry;

/**
 * Renders a tool's raw JSON output as a human-readable text block consumable
 * by a CI section reader. Falls back to the raw output for jobs without a
 * registered parser, broken JSON, or any case where no issues are parsed —
 * the operator must always see at least the original tool output when KO.
 *
 * Drives BUG-18: structured formats (codeclimate, sarif) reconfigure the
 * tools to emit JSON for the file-based formatters, and that JSON used to
 * leak into the KO section as the visible body.
 */
final class HumanIssueFormatter
{
    use RelativizesFilePath;

    private ToolOutputParserRegistry $registry;

    public function __construct(ToolOutputParserRegistry $registry)
    {
        $this->registry = $registry;
    }

    public function format(string $jobType, string $rawOutput): string
    {
        if (trim($rawOutput) === '') {
            return '';
        }
        if (!$this->registry->hasParser($jobType)) {
            return $rawOutput;
        }

        $parser = $this->registry->getParser($jobType);
        if ($parser === null) {
            return $rawOutput;
        }

        $issues = $parser->parse($rawOutput, $jobType);
        if (count($issues) === 0) {
            return $rawOutput;
        }

        return $this->renderIssues($issues);
    }

    /**
     * @param CodeIssue[] $issues
     */
    private function renderIssues(array $issues): string
    {
        /** @var array<string, CodeIssue[]> $byFile */
        $byFile = [];
        foreach ($issues as $issue) {
            $file = $this->relativizePath($issue->getFile());
            if (!isset($byFile[$file])) {
                $byFile[$file] = [];
            }
            $byFile[$file][] = $issue;
        }

        $lines = [];
        foreach ($byFile as $file => $fileIssues) {
            $lines[] = $file;
            foreach ($fileIssues as $issue) {
                $location = 'line ' . $issue->getLine();
                $column = $issue->getColumn();
                if ($column !== null) {
                    $location .= ':' . $column;
                }
                $lines[] = '  ' . $location . '  ' . $issue->getMessage() . '  [' . $issue->getRuleId() . ']';
            }
            $lines[] = '';
        }

        $fileCount = count($byFile);
        $issueCount = count($issues);
        $lines[] = sprintf(
            'Totals: %d %s, %d %s',
            $fileCount,
            $fileCount === 1 ? 'file' : 'files',
            $issueCount,
            $issueCount === 1 ? 'issue' : 'issues'
        );

        return implode("\n", $lines) . "\n";
    }
}
