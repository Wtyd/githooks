<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Configuration;

class OptionsConfiguration
{
    private bool $failFast;

    private int $processes;

    public function __construct(bool $failFast = false, int $processes = 1)
    {
        $this->failFast = $failFast;
        $this->processes = $processes;
    }

    /**
     * Build from raw config array. Validates and collects errors/warnings.
     *
     * @param array<string, mixed> $raw The 'options' section (from flows level or per-flow)
     */
    public static function fromArray(array $raw, ValidationResult $result): self
    {
        $failFast = false;
        $processes = 1;

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

        $knownKeys = ['fail-fast', 'processes'];
        foreach (array_keys($raw) as $key) {
            if (!in_array($key, $knownKeys, true)) {
                $result->addWarning("Unknown option '$key'. It will be ignored.");
            }
        }

        return new self($failFast, $processes);
    }

    public function isFailFast(): bool
    {
        return $this->failFast;
    }

    public function getProcesses(): int
    {
        return $this->processes;
    }
}
