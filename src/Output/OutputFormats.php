<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output;

/**
 * Centralised lists of output formats. Single source of truth for both the
 * `--format=` flag (`SUPPORTED`) and the `--report-*` flags / `reports`
 * config map (`STRUCTURED`).
 */
final class OutputFormats
{
    /** Formats that produce machine-readable output and can be written as report files. */
    public const STRUCTURED = ['json', 'junit', 'sarif', 'codeclimate'];

    /**
     * Stdout-only AI stop-hook protocol (FEAT-15). Emits clean stdout like the
     * structured formats, but is NOT in STRUCTURED: it cannot be a `--report-*`
     * target and does not request tool-level JSON.
     */
    public const CLAUDE_CODE = 'claude-code';

    /** Formats accepted by `--format=` (structured set + plain text + AI stop-hook). */
    public const SUPPORTED = ['text', 'json', 'junit', 'sarif', 'codeclimate', self::CLAUDE_CODE];

    /**
     * Formats accepted by the diagnostic commands (`conf:check`, `status`,
     * `system:info`). These commands expose only `text` and `json`: the
     * report-file formats (junit/sarif/codeclimate) and the `claude-code`
     * stop-hook protocol describe execution results, not diagnostics.
     */
    public const DIAGNOSTIC = ['text', 'json'];

    private function __construct()
    {
    }

    /**
     * Whether the format writes a clean machine payload to stdout — the
     * structured formats plus the `claude-code` stop-hook protocol. Such
     * formats must keep stdout free of the conditions header, CI decorations
     * and progress noise (those go to stderr, or are suppressed), so the
     * payload stays a single parseable document.
     */
    public static function hasCleanStdout(string $format): bool
    {
        return in_array($format, self::STRUCTURED, true) || $format === self::CLAUDE_CODE;
    }

    /**
     * Resolve the process exit code for the given format and flow outcome.
     *
     * Every format maps a failed run to exit 1 except `claude-code`: the Claude
     * Code stop-hook protocol only honours the `{"decision":"block"}` JSON when
     * the process exits 0. A non-zero exit makes Claude Code surface stderr and
     * confuse it with the native block, so the format always exits 0 and signals
     * the block through stdout instead.
     */
    public static function exitCodeFor(string $format, bool $success): int
    {
        if ($format === self::CLAUDE_CODE) {
            return 0;
        }

        return $success ? 0 : 1;
    }
}
