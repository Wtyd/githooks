<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output\ToolOutputParser;

use Wtyd\GitHooks\Output\CodeIssue;

/**
 * Parses PHPCS JSON output (--report=json).
 *
 * Input: {"totals":{...},"files":{"path":{"messages":[{line,column,message,source,type}]}}}
 */
class PhpcsOutputParser implements ToolOutputParserInterface
{
    /**
     * @return CodeIssue[]
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Defensive parsing of external JSON structure
     */
    public function parse(string $stdout, string $toolName): array
    {
        $data = json_decode($stdout, true);
        if (!is_array($data) || !isset($data['files'])) {
            return [];
        }

        $issues = [];
        foreach ($data['files'] as $file => $fileData) {
            if (!is_array($fileData) || !isset($fileData['messages'])) {
                continue;
            }
            foreach ($fileData['messages'] as $msg) {
                if (!is_array($msg) || !isset($msg['line'], $msg['message'])) {
                    continue;
                }
                $severity = isset($msg['type']) && strtoupper((string) $msg['type']) === 'ERROR'
                    ? 'error'
                    : 'warning';

                $issues[] = new CodeIssue(
                    (string) $file,
                    (int) $msg['line'],
                    null,
                    isset($msg['column']) ? (int) $msg['column'] : null,
                    (string) $msg['message'],
                    (string) ($msg['source'] ?? 'phpcs'),
                    $severity,
                    $toolName
                );
            }
        }

        return $issues;
    }
}
