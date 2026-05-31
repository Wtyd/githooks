<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output\Concerns;

/**
 * Strips ANSI escape sequences (colour codes, cursor moves, OSC sequences) and
 * carriage returns from tool output.
 *
 * Tool stdout is captured with colours intact for the human text renderer, but
 * machine-readable payloads (JSON v2, the Claude Code stop-hook protocol) must
 * carry clean, plain text so consumers and `json_decode` see no control bytes.
 */
trait StripsAnsi
{
    private function stripAnsi(string $text): string
    {
        return (string) preg_replace('/\x1B(?:\[[0-9;]*[A-Za-z]|\][^\x07]*\x07)|\r/', '', $text);
    }
}
