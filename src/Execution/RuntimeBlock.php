<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

/**
 * Runtime span + runner snapshot for a flow (FEAT-14). Attached to
 * {@see FlowResult} so {@see \Wtyd\GitHooks\Output\JsonResultFormatter}
 * serialises the always-present `runtime` JSON node.
 */
final class RuntimeBlock
{
    private Diagnostics $diagnostics;

    private string $startedAt;

    private ?string $endedAt;

    public function __construct(Diagnostics $diagnostics, string $startedAt, ?string $endedAt = null)
    {
        $this->diagnostics = $diagnostics;
        $this->startedAt = $startedAt;
        $this->endedAt = $endedAt;
    }

    public function withEndedAt(string $endedAt): self
    {
        $clone = clone $this;
        $clone->endedAt = $endedAt;
        return $clone;
    }

    public function getDiagnostics(): Diagnostics
    {
        return $this->diagnostics;
    }

    public function getStartedAt(): string
    {
        return $this->startedAt;
    }

    public function getEndedAt(): ?string
    {
        return $this->endedAt;
    }
}
