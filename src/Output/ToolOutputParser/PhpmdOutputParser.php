<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output\ToolOutputParser;

use Wtyd\GitHooks\Output\CodeIssue;
use Wtyd\GitHooks\Output\ToolOutputParser\Concerns\ExtractsJsonDocument;

/**
 * Parses PHPMD JSON output (json renderer, PHPMD 2.6+).
 *
 * Input: {"files":[{"file":"/abs/path","violations":[{beginLine,endLine,description,rule,priority}]}]}
 *
 * Uses ExtractsJsonDocument to tolerate prologues/epilogues added by the
 * tool or merged from stderr — the same defensive pattern applied to phpstan.
 */
class PhpmdOutputParser implements ToolOutputParserInterface
{
    use ExtractsJsonDocument;

    /**
     * @return CodeIssue[]
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Defensive parsing of external JSON structure
     * @SuppressWarnings(PHPMD.NPathComplexity) Defensive parsing with multiple null checks
     */
    public function parse(string $stdout, string $toolName): array
    {
        $data = json_decode($this->extractJsonDocument($stdout), true);
        if (!is_array($data) || !isset($data['files'])) {
            return [];
        }

        $cwd = getcwd() ?: null;

        $issues = [];
        foreach ($data['files'] as $fileEntry) {
            if (!is_array($fileEntry) || !isset($fileEntry['file'], $fileEntry['violations'])) {
                continue;
            }
            $file = $this->makeRelative((string) $fileEntry['file'], $cwd);

            foreach ($fileEntry['violations'] as $violation) {
                if (!is_array($violation) || !isset($violation['beginLine'], $violation['description'])) {
                    continue;
                }

                $priority = isset($violation['priority']) ? (int) $violation['priority'] : 3;
                $severity = $priority <= 2 ? 'error' : 'warning';

                $issues[] = new CodeIssue(
                    $file,
                    (int) $violation['beginLine'],
                    isset($violation['endLine']) ? (int) $violation['endLine'] : null,
                    null,
                    (string) $violation['description'],
                    (string) ($violation['rule'] ?? 'phpmd'),
                    $severity,
                    $toolName
                );
            }
        }

        return $issues;
    }

    /**
     * Strip CWD prefix to make absolute paths relative.
     */
    private function makeRelative(string $path, ?string $cwd): string
    {
        if ($cwd === null) {
            return $path;
        }
        $prefix = rtrim($cwd, '/\\') . '/';
        if (strpos($path, $prefix) === 0) {
            return substr($path, strlen($prefix));
        }
        return $path;
    }
}
