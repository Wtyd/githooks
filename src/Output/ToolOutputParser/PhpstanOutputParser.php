<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output\ToolOutputParser;

use Wtyd\GitHooks\Output\CodeIssue;
use Wtyd\GitHooks\Output\ToolOutputParser\Concerns\ExtractsJsonDocument;

/**
 * Parses PHPStan JSON output (--error-format=json).
 *
 * Input: {"totals":{"errors":N},"files":{"path":{messages:[{line,message,identifier,ignorable}]}}}
 *
 * PHPStan 2.x prints a human preamble on stderr, and some capture paths
 * (piped CI runners, wrappers that merge both streams) surface that
 * preamble together with the JSON payload. The trait ExtractsJsonDocument
 * slices the payload down to the JSON portion so the parser stays robust
 * to such prologues and any future epilogue phpstan might add.
 *
 * The `identifier` field (phpstan 2.x) carries a specific rule code such as
 * "missingType.return" or "variable.undefined". When present, it is used as
 * the CodeIssue ruleId so SARIF consumers (GitHub Code Scanning) can group
 * and filter alerts by type. Older phpstan versions without `identifier`
 * fall back to the generic `phpstan` rule id.
 */
class PhpstanOutputParser implements ToolOutputParserInterface
{
    use ExtractsJsonDocument;

    /** @return CodeIssue[] */
    public function parse(string $stdout, string $toolName): array
    {
        $data = json_decode($this->extractJsonDocument($stdout), true);
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
                    $this->extractRuleId($msg),
                    'error',
                    $toolName
                );
            }
        }

        return $issues;
    }

    /**
     * Extract the phpstan-specific identifier (phpstan 2.x) or fall back to
     * the generic 'phpstan' rule id when the field is missing/empty.
     *
     * @param array<string, mixed> $msg
     */
    private function extractRuleId(array $msg): string
    {
        if (isset($msg['identifier']) && $msg['identifier'] !== '') {
            return (string) $msg['identifier'];
        }
        return 'phpstan';
    }
}
