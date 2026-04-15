<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output;

/**
 * A single code quality issue extracted from a tool's structured output.
 * Common model shared by all tool parsers and consumed by Code Climate / SARIF formatters.
 */
class CodeIssue
{
    private string $file;

    private int $line;

    private ?int $endLine;

    private ?int $column;

    private string $message;

    private string $ruleId;

    /** @var string One of: info, warning, error, critical */
    private string $severity;

    private string $toolName;

    public function __construct(
        string $file,
        int $line,
        ?int $endLine,
        ?int $column,
        string $message,
        string $ruleId,
        string $severity,
        string $toolName
    ) {
        $this->file = $file;
        $this->line = $line;
        $this->endLine = $endLine;
        $this->column = $column;
        $this->message = $message;
        $this->ruleId = $ruleId;
        $this->severity = $severity;
        $this->toolName = $toolName;
    }

    public function getFile(): string
    {
        return $this->file;
    }

    public function getLine(): int
    {
        return $this->line;
    }

    public function getEndLine(): ?int
    {
        return $this->endLine;
    }

    public function getColumn(): ?int
    {
        return $this->column;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getRuleId(): string
    {
        return $this->ruleId;
    }

    public function getSeverity(): string
    {
        return $this->severity;
    }

    public function getToolName(): string
    {
        return $this->toolName;
    }

    public function getFingerprint(): string
    {
        return md5($this->file . ':' . $this->line . ':' . $this->ruleId);
    }
}
