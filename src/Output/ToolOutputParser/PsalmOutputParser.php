<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output\ToolOutputParser;

use Wtyd\GitHooks\Output\CodeIssue;

/**
 * Parses Psalm JSON output (--output-format=json).
 *
 * Input: [{file_name,line_from,line_to,column_from,type,message,severity}]
 */
class PsalmOutputParser implements ToolOutputParserInterface
{
    /** @return CodeIssue[] */
    public function parse(string $stdout, string $toolName): array
    {
        $data = json_decode($stdout, true);
        if (!is_array($data)) {
            return [];
        }

        $issues = [];
        foreach ($data as $item) {
            if (!is_array($item) || !isset($item['file_name'], $item['line_from'], $item['message'])) {
                continue;
            }

            $severity = isset($item['severity']) && $item['severity'] === 'error' ? 'error' : 'info';

            $issues[] = new CodeIssue(
                (string) $item['file_name'],
                (int) $item['line_from'],
                isset($item['line_to']) ? (int) $item['line_to'] : null,
                isset($item['column_from']) ? (int) $item['column_from'] : null,
                (string) $item['message'],
                (string) ($item['type'] ?? 'psalm'),
                $severity,
                $toolName
            );
        }

        return $issues;
    }
}
