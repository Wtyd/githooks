<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Configuration;

use Wtyd\GitHooks\Configuration\KeySuggestion;
use Wtyd\GitHooks\Output\OutputFormats;

/**
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Value object that validates each option key
 *   independently — the breadth of options is intrinsic to the configuration surface.
 */
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

    private ?TimeBudgetConfiguration $timeBudget;

    private ?MemoryBudgetConfiguration $memoryBudget;

    private string $allocator;

    private bool $stats;

    /** @var array<string, true> Keys explicitly declared in raw config (used by EffectiveOptionsResolver) */
    private array $declaredKeys = [];

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
        array $reports = [],
        ?TimeBudgetConfiguration $timeBudget = null,
        ?MemoryBudgetConfiguration $memoryBudget = null,
        string $allocator = AllocatorStrategy::FIFO,
        bool $stats = false
    ) {
        $this->failFast = $failFast;
        $this->processes = $processes;
        $this->mainBranch = $mainBranch;
        $this->fastBranchFallback = $fastBranchFallback;
        $this->executablePrefix = $executablePrefix;
        $this->reports = $reports;
        $this->timeBudget = $timeBudget;
        $this->memoryBudget = $memoryBudget;
        $this->allocator = $allocator;
        $this->stats = $stats;
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

        $timeBudget = null;
        if (array_key_exists(TimeBudgetConfiguration::KEY, $raw)) {
            $timeBudget = TimeBudgetConfiguration::fromArray($raw[TimeBudgetConfiguration::KEY], $result);
        }

        $memoryBudget = self::parseMemoryBudget($raw, $result);
        $allocator = self::parseAllocator($raw, $result);
        $stats = self::parseStats($raw, $result);

        self::reportUnknownAndCliOnlyKeys($raw, $result);

        $instance = new self(
            $failFast,
            $processes,
            $mainBranch,
            $fastBranchFallback,
            $executablePrefix,
            $reports,
            $timeBudget,
            $memoryBudget,
            $allocator,
            $stats
        );
        foreach (self::knownTopLevelKeys() as $key) {
            if (array_key_exists($key, $raw)) {
                $instance->declaredKeys[$key] = true;
            }
        }
        return $instance;
    }

    /**
     * Report unknown options as warnings (with did-you-mean suggestion) and
     * CLI-only keys as hard errors. Extracted from `fromArray()` to keep that
     * method below the 100-line / NPath threshold.
     *
     * @param array<string, mixed> $raw
     */
    private static function reportUnknownAndCliOnlyKeys(array $raw, ValidationResult $result): void
    {
        $knownKeys = self::knownTopLevelKeys();
        // CLI-only keys (`files`, `files-from`, `exclude-pattern`) are runtime
        // selection flags driven by --files / --files-from / --exclude-pattern;
        // declaring them in config bakes volatile input into a persistent flow.
        $cliOnlyKeys = ['files', 'files-from', 'exclude-pattern'];
        foreach (array_keys($raw) as $key) {
            if (in_array($key, $cliOnlyKeys, true)) {
                $result->addError(
                    "Option '$key' is CLI-only and cannot be declared in flow.options. "
                    . "Use --$key on the command line instead."
                );
                continue;
            }
            if (!in_array($key, $knownKeys, true)) {
                $suggestion = KeySuggestion::suggestionFor((string) $key, $knownKeys);
                $result->addWarning("Unknown option '$key'. It will be ignored.{$suggestion}");
            }
        }
    }

    /**
     * Canonical list of accepted keys at the flow.options level. Used both by
     * the unknown-key validator and by the declaredKeys tracker so the source
     * of truth lives in a single place.
     *
     * @return string[]
     */
    private static function knownTopLevelKeys(): array
    {
        return [
            'fail-fast',
            'processes',
            'main-branch',
            'fast-branch-fallback',
            'executable-prefix',
            'reports',
            TimeBudgetConfiguration::KEY,
            MemoryBudgetConfiguration::KEY,
            'allocator',
            'stats',
        ];
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function parseMemoryBudget(array $raw, ValidationResult $result): ?MemoryBudgetConfiguration
    {
        if (!array_key_exists(MemoryBudgetConfiguration::KEY, $raw)) {
            return null;
        }
        $value = $raw[MemoryBudgetConfiguration::KEY];
        if ($value === null) {
            return null;
        }
        return MemoryBudgetConfiguration::fromArray($value, $result);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function parseAllocator(array $raw, ValidationResult $result): string
    {
        if (!array_key_exists('allocator', $raw)) {
            return AllocatorStrategy::FIFO;
        }
        $value = $raw['allocator'];
        if (!is_string($value) || !AllocatorStrategy::isValid($value)) {
            $valid = implode(', ', AllocatorStrategy::ALL);
            $shown = is_string($value) ? $value : (string) $value;
            $result->addError("'allocator' must be one of: $valid (got '$shown').");
            return AllocatorStrategy::FIFO;
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function parseStats(array $raw, ValidationResult $result): bool
    {
        if (!array_key_exists('stats', $raw)) {
            return false;
        }
        if (!is_bool($raw['stats'])) {
            $result->addError("'stats' must be a boolean value.");
            return false;
        }
        return $raw['stats'];
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

    public function getTimeBudget(): ?TimeBudgetConfiguration
    {
        return $this->timeBudget;
    }

    public function getMemoryBudget(): ?MemoryBudgetConfiguration
    {
        return $this->memoryBudget;
    }

    public function getAllocator(): string
    {
        return $this->allocator;
    }

    public function isStats(): bool
    {
        return $this->stats;
    }

    public static function defaults(): self
    {
        return new self();
    }

    /**
     * Whether the given option key was explicitly declared in the raw config.
     * Returns false for both unknown and absent keys, and for instances built without fromArray().
     */
    public function hasKey(string $key): bool
    {
        return isset($this->declaredKeys[$key]);
    }

    /**
     * Cascade the three block-level keys — `executable-prefix`,
     * `fast-branch-fallback` and `reports` — from `$flow` into `$global`
     * per-key (BUG-20). When `$flow` declares the key the flow value wins;
     * when it doesn't, the global value is inherited. The remaining fields
     * are taken from `$flow` verbatim (callers that need the full per-key
     * cascade for `fail-fast`, `processes`, etc. — i.e. EffectiveOptionsResolver —
     * overwrite them with the CLI-aware cascade afterwards).
     *
     * Returns `$global` unchanged when `$flow` is null.
     */
    public static function cascadeBlockKeysFromFlow(
        ?self $flow,
        self $global
    ): self {
        if ($flow === null) {
            return $global;
        }
        $cascadeString = static function (string $key, callable $reader) use ($flow, $global): string {
            return (string) ($flow->hasKey($key) ? $reader($flow) : $reader($global));
        };
        $cascadeArray = static function (string $key, callable $reader) use ($flow, $global): array {
            return (array) ($flow->hasKey($key) ? $reader($flow) : $reader($global));
        };

        return new self(
            $flow->failFast,
            $flow->processes,
            $flow->mainBranch,
            $cascadeString('fast-branch-fallback', fn(self $opts) => $opts->getFastBranchFallback()),
            $cascadeString('executable-prefix', fn(self $opts) => $opts->getExecutablePrefix()),
            $cascadeArray('reports', fn(self $opts) => $opts->getReports()),
            $flow->timeBudget,
            $flow->memoryBudget,
            $flow->allocator,
            $flow->stats
        );
    }
}
