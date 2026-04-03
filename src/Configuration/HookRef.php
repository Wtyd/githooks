<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Configuration;

/**
 * A reference from a hook event to a flow or job, with optional execution conditions.
 */
class HookRef
{
    private string $target;

    /** @var string[] */
    private array $onlyOnBranches;

    /** @var string[] */
    private array $onlyFiles;

    /**
     * @param string[] $onlyOnBranches
     * @param string[] $onlyFiles
     */
    public function __construct(string $target, array $onlyOnBranches = [], array $onlyFiles = [])
    {
        $this->target = $target;
        $this->onlyOnBranches = $onlyOnBranches;
        $this->onlyFiles = $onlyFiles;
    }

    /**
     * Create from a simple string reference (retrocompatible).
     */
    public static function fromString(string $ref): self
    {
        return new self($ref);
    }

    /**
     * Create from an extended array format with conditions.
     *
     * @param array<string, mixed> $raw e.g. ['flow' => 'qa', 'only-on' => ['main']]
     */
    public static function fromArray(array $raw, ValidationResult $result): ?self
    {
        $target = $raw['flow'] ?? $raw['job'] ?? null;

        if ($target === null || !is_string($target)) {
            $result->addError("Hook ref must have a 'flow' or 'job' key with a string value.");
            return null;
        }

        $onlyOn = [];
        if (isset($raw['only-on'])) {
            $onlyOn = is_array($raw['only-on']) ? $raw['only-on'] : [$raw['only-on']];
        }

        $onlyFiles = [];
        if (isset($raw['only-files'])) {
            $onlyFiles = is_array($raw['only-files']) ? $raw['only-files'] : [$raw['only-files']];
        }

        $knownKeys = ['flow', 'job', 'only-on', 'only-files'];
        foreach (array_keys($raw) as $key) {
            if (!in_array($key, $knownKeys, true)) {
                $result->addWarning("Unknown key '$key' in hook ref for '$target'.");
            }
        }

        return new self($target, $onlyOn, $onlyFiles);
    }

    public function getTarget(): string
    {
        return $this->target;
    }

    /** @return string[] */
    public function getOnlyOnBranches(): array
    {
        return $this->onlyOnBranches;
    }

    /** @return string[] */
    public function getOnlyFiles(): array
    {
        return $this->onlyFiles;
    }

    public function hasConditions(): bool
    {
        return !empty($this->onlyOnBranches) || !empty($this->onlyFiles);
    }
}
