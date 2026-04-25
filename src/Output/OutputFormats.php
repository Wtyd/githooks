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

    /** Formats accepted by `--format=` (structured set + plain text). */
    public const SUPPORTED = ['text', 'json', 'junit', 'sarif', 'codeclimate'];

    private function __construct()
    {
    }
}
