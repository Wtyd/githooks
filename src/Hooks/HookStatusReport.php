<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Hooks;

class HookStatusReport
{
    private bool $hooksPathConfigured;

    private string $hooksPathValue;

    /** @var HookEventStatus[] */
    private array $events;

    /**
     * @param HookEventStatus[] $events
     */
    public function __construct(bool $hooksPathConfigured, string $hooksPathValue, array $events)
    {
        $this->hooksPathConfigured = $hooksPathConfigured;
        $this->hooksPathValue = $hooksPathValue;
        $this->events = $events;
    }

    public function isHooksPathConfigured(): bool
    {
        return $this->hooksPathConfigured;
    }

    public function getHooksPathValue(): string
    {
        return $this->hooksPathValue;
    }

    /** @return HookEventStatus[] */
    public function getEvents(): array
    {
        return $this->events;
    }
}
