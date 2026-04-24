<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output\ToolOutputParser\Concerns;

/**
 * Extracts the JSON document from a raw tool output that may include a human
 * preamble or epilogue. PHPStan 2.x, for example, prints an instructional
 * block on stderr that some capture paths merge with stdout; defending
 * against that keeps the parsers stable across tool versions.
 */
trait ExtractsJsonDocument
{
    /**
     * Slice the input so only the JSON document remains (strip any human
     * prologue/epilogue). Returns an empty string when no JSON-like
     * delimiters are present.
     */
    private function extractJsonDocument(string $stdout): string
    {
        $start = strpos($stdout, '{');
        $end = strrpos($stdout, '}');
        if ($start === false || $end === false || $end < $start) {
            return '';
        }
        return substr($stdout, $start, $end - $start + 1);
    }
}
