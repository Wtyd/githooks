<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Configuration;

use Wtyd\GitHooks\Output\OutputFormats;

class OptionsConfiguration
{
    /**
     * @deprecated Use {@see OutputFormats::STRUCTURED} directly.
     * Kept as a backwards-compatible alias for the structured format set.
     */
    public const VALID_REPORT_FORMATS = OutputFormats::STRUCTURED;

    private bool $failFast;

    private int $processes;

    private ?string $mainBranch;

    private string $fastBranchFallback;

    private string $executablePrefix;

    /** @var array<string, string> Map [format => path] for declarative multi-report. */
    private array $reports;

    /**
     * @param array<string, string> $reports Map of report format → output path
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Value object — boolean is the natural type
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Value object with one parameter per option
     */
    public function __construct(
        bool $failFast = false,
        int $processes = 1,
        ?string $mainBranch = null,
        string $fastBranchFallback = 'full',
        string $executablePrefix = '',
        array $reports = []
    ) {
        $this->failFast = $failFast;
        $this->processes = $processes;
        $this->mainBranch = $mainBranch;
        $this->fastBranchFallback = $fastBranchFallback;
        $this->executablePrefix = $executablePrefix;
        $this->reports = $reports;
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

        $executablePrefix = '';
        if (array_key_exists('executable-prefix', $raw)) {
            if (!is_string($raw['executable-prefix'])) {
                $result->addError("'executable-prefix' must be a string.");
            } else {
                $executablePrefix = $raw['executable-prefix'];
            }
        }

        $reports = self::parseReports($raw, $result);

        $knownKeys = ['fail-fast', 'processes', 'main-branch', 'fast-branch-fallback', 'executable-prefix', 'reports'];
        foreach (array_keys($raw) as $key) {
            if (!in_array($key, $knownKeys, true)) {
                $result->addWarning("Unknown option '$key'. It will be ignored.");
            }
        }

        return new self($failFast, $processes, $mainBranch, $fastBranchFallback, $executablePrefix, $reports);
    }

    /**
     * Validate and extract the `reports` map. Returns [] when absent or invalid;
     * errors are collected in the ValidationResult.
     *
     * @param array<string, mixed> $raw
     * @return array<string, string>
     */
    private static function parseReports(array $raw, ValidationResult $result): array
    {
        if (!array_key_exists('reports', $raw)) {
            return [];
        }

        if (!is_array($raw['reports'])) {
            $result->addError("'reports' must be a map of format => path.");
            return [];
        }

        $reports = [];
        foreach ($raw['reports'] as $format => $path) {
            if (!is_string($format) || !in_array($format, OutputFormats::STRUCTURED, true)) {
                $valid = implode(', ', OutputFormats::STRUCTURED);
                $shown = is_string($format) ? $format : (string) $format;
                $result->addError("'reports' contains invalid format '$shown'. Valid formats: $valid.");
                continue;
            }
            if (!is_string($path) || $path === '') {
                $result->addError("'reports.$format' must be a non-empty string path.");
                continue;
            }
            $reports[$format] = $path;
        }

        return $reports;
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

    public function getExecutablePrefix(): string
    {
        return $this->executablePrefix;
    }

    /**
     * @return array<string, string> Map [format => path] for declarative multi-report
     */
    public function getReports(): array
    {
        return $this->reports;
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
            $this->fastBranchFallback,
            $this->executablePrefix,
            $this->reports
        );
    }
}
