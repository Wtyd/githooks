<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Hooks;

class HookEventStatus
{
    public const STATUS_SYNCED = 'synced';
    public const STATUS_MISSING = 'missing';
    public const STATUS_ORPHAN = 'orphan';

    private string $event;

    private string $status;

    private bool $executable;

    /** @var string[] */
    private array $targets;

    /**
     * @param string[] $targets
     */
    public function __construct(string $event, string $status, bool $executable, array $targets = [])
    {
        $this->event = $event;
        $this->status = $status;
        $this->executable = $executable;
        $this->targets = $targets;
    }

    public function getEvent(): string
    {
        return $this->event;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isExecutable(): bool
    {
        return $this->executable;
    }

    /** @return string[] */
    public function getTargets(): array
    {
        return $this->targets;
    }
}
