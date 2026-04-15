<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output\ToolOutputParser;

use Wtyd\GitHooks\Output\CodeIssue;

/**
 * Parses PHP Parallel Lint JSON output (--json).
 *
 * Input: {"results":{"errors":[{type,file,line,message}]}}
 */
class ParallelLintOutputParser implements ToolOutputParserInterface
{
    /** @return CodeIssue[] */
    public function parse(string $stdout, string $toolName): array
    {
        $data = json_decode($stdout, true);
        if (!is_array($data) || !isset($data['results']['errors'])) {
            return [];
        }

        $cwd = getcwd() ?: null;

        $issues = [];
        foreach ($data['results']['errors'] as $error) {
            if (!is_array($error) || !isset($error['file'], $error['line'], $error['message'])) {
                continue;
            }

            $file = $this->makeRelative((string) $error['file'], $cwd);

            $issues[] = new CodeIssue(
                $file,
                (int) $error['line'],
                null,
                null,
                (string) $error['message'],
                'SyntaxError',
                'error',
                $toolName
            );
        }

        return $issues;
    }

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
