<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output\ToolOutputParser;

use Wtyd\GitHooks\Output\CodeIssue;

/**
 * Parses PHPStan JSON output (--error-format=json).
 *
 * Input: {"totals":{"errors":N},"files":{"path":{messages:[{line,message,ignorable}]}}}
 */
class PhpstanOutputParser implements ToolOutputParserInterface
{
    /** @return CodeIssue[] */
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
                $issues[] = new CodeIssue(
                    (string) $file,
                    (int) $msg['line'],
                    null,
                    null,
                    (string) $msg['message'],
                    'phpstan',
                    'error',
                    $toolName
                );
            }
        }

        return $issues;
    }
}
