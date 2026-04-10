<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Configuration;

use Wtyd\GitHooks\Execution\ExecutionMode;

/**
 * A reference from a hook event to a flow or job, with optional execution conditions.
 */
class HookRef
{
    private string $target;

    /** @var string[] */
    private array $onlyOnBranches;

    /** @var string[] */
    private array $excludeOnBranches;

    /** @var string[] */
    private array $onlyFiles;

    /** @var string[] */
    private array $excludeFiles;

    private ?string $execution;

    /**
     * @param string[] $onlyOnBranches
     * @param string[] $excludeOnBranches
     * @param string[] $onlyFiles
     * @param string[] $excludeFiles
     */
    public function __construct(
        string $target,
        array $onlyOnBranches = [],
        array $onlyFiles = [],
        array $excludeFiles = [],
        array $excludeOnBranches = [],
        ?string $execution = null
    ) {
        $this->target = $target;
        $this->onlyOnBranches = $onlyOnBranches;
        $this->excludeOnBranches = $excludeOnBranches;
        $this->onlyFiles = $onlyFiles;
        $this->excludeFiles = $excludeFiles;
        $this->execution = $execution;
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
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Parses multiple optional condition keys with type normalization
     * @SuppressWarnings(PHPMD.NPathComplexity) Each condition key adds an independent branch
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

        $excludeOn = [];
        if (isset($raw['exclude-on'])) {
            $excludeOn = is_array($raw['exclude-on']) ? $raw['exclude-on'] : [$raw['exclude-on']];
        }

        $onlyFiles = [];
        if (isset($raw['only-files'])) {
            $onlyFiles = is_array($raw['only-files']) ? $raw['only-files'] : [$raw['only-files']];
        }

        $excludeFiles = [];
        if (isset($raw['exclude-files'])) {
            $excludeFiles = is_array($raw['exclude-files']) ? $raw['exclude-files'] : [$raw['exclude-files']];
        }

        $execution = null;
        if (isset($raw['execution'])) {
            if (!is_string($raw['execution']) || !ExecutionMode::isValid($raw['execution'])) {
                $result->addError("Hook ref for '$target': 'execution' must be one of: " . implode(', ', ExecutionMode::ALL) . ".");
                return null;
            }
            $execution = $raw['execution'];
        }

        $knownKeys = ['flow', 'job', 'only-on', 'exclude-on', 'only-files', 'exclude-files', 'execution'];
        foreach (array_keys($raw) as $key) {
            if (!in_array($key, $knownKeys, true)) {
                $result->addWarning("Unknown key '$key' in hook ref for '$target'.");
            }
        }

        return new self($target, $onlyOn, $onlyFiles, $excludeFiles, $excludeOn, $execution);
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
    public function getExcludeOnBranches(): array
    {
        return $this->excludeOnBranches;
    }

    /** @return string[] */
    public function getOnlyFiles(): array
    {
        return $this->onlyFiles;
    }

    /** @return string[] */
    public function getExcludeFiles(): array
    {
        return $this->excludeFiles;
    }

    public function getExecution(): ?string
    {
        return $this->execution;
    }

    public function hasConditions(): bool
    {
        return !empty($this->onlyOnBranches)
            || !empty($this->excludeOnBranches)
            || !empty($this->onlyFiles)
            || !empty($this->excludeFiles);
    }
}
