<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Configuration;

class OptionsConfiguration
{
    private bool $failFast;

    private int $processes;

    private ?string $mainBranch;

    private string $fastBranchFallback;

    /** @SuppressWarnings(PHPMD.BooleanArgumentFlag) Value object — boolean is the natural type */
    public function __construct(bool $failFast = false, int $processes = 1, ?string $mainBranch = null, string $fastBranchFallback = 'full')
    {
        $this->failFast = $failFast;
        $this->processes = $processes;
        $this->mainBranch = $mainBranch;
        $this->fastBranchFallback = $fastBranchFallback;
    }

    /**
     * Build from raw config array. Validates and collects errors/warnings.
     *
     * @param array<string, mixed> $raw The 'options' section (from flows level or per-flow)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Validates multiple independent option keys
     * @SuppressWarnings(PHPMD.NPathComplexity) Each optional config key adds an independent validation branch
     */
    public static function fromArray(array $raw, ValidationResult $result): self
    {
        $failFast = false;
        $processes = 1;
        $mainBranch = null;
        $fastBranchFallback = 'full';

        if (array_key_exists('fail-fast', $raw)) {
            if (!is_bool($raw['fail-fast'])) {
                $result->addError("'fail-fast' must be a boolean value.");
            } else {
                $failFast = $raw['fail-fast'];
            }
        }

        if (array_key_exists('processes', $raw)) {
            if (!is_int($raw['processes']) || $raw['processes'] < 1) {
                $result->addError("'processes' must be a positive integer.");
            } else {
                $processes = $raw['processes'];
            }
        }

        if (array_key_exists('main-branch', $raw)) {
            if (!is_string($raw['main-branch'])) {
                $result->addError("'main-branch' must be a string.");
            } else {
                $mainBranch = $raw['main-branch'];
            }
        }

        if (array_key_exists('fast-branch-fallback', $raw)) {
            $value = $raw['fast-branch-fallback'];
            if (!is_string($value) || !in_array($value, ['fast', 'full'], true)) {
                $result->addError("'fast-branch-fallback' must be 'fast' or 'full'.");
            } else {
                $fastBranchFallback = $value;
            }
        }

        $knownKeys = ['fail-fast', 'processes', 'main-branch', 'fast-branch-fallback'];
        foreach (array_keys($raw) as $key) {
            if (!in_array($key, $knownKeys, true)) {
                $result->addWarning("Unknown option '$key'. It will be ignored.");
            }
        }

        return new self($failFast, $processes, $mainBranch, $fastBranchFallback);
    }

    public function isFailFast(): bool
    {
        return $this->failFast;
    }

    public function getProcesses(): int
    {
        return $this->processes;
    }

    public function getMainBranch(): ?string
    {
        return $this->mainBranch;
    }

    public function getFastBranchFallback(): string
    {
        return $this->fastBranchFallback;
    }

    public static function defaults(): self
    {
        return new self();
    }

    /**
     * Return a new instance with CLI overrides applied.
     * Only non-null values override the current config.
     */
    public function withOverrides(?bool $failFast, ?int $processes): self
    {
        return new self(
            $failFast !== null ? $failFast : $this->failFast,
            $processes !== null ? $processes : $this->processes,
            $this->mainBranch,
            $this->fastBranchFallback
        );
    }
}
