<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Configuration;

use Wtyd\GitHooks\Configuration\KeySuggestion;
use Wtyd\GitHooks\Execution\ExecutionMode;
use Wtyd\GitHooks\Jobs\JobRegistry;
use Wtyd\GitHooks\Registry\ToolRegistry;

/** @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Validation of type, execution mode, arguments, and common keys */
class JobConfiguration
{
    /**
     * Tool type → native threading argument key. When both `cores` and this
     * key are set, `cores` wins and conf:check emits a warning. phpstan is
     * absent on purpose: it has no CLI flag for workers (the .neon decides).
     */
    private const THREAD_ARG_KEYS = [
        'phpcs'         => 'parallel',
        'phpcbf'        => 'parallel',
        'psalm'         => 'threads',
        'parallel-lint' => 'jobs',
        'paratest'      => 'processes',
    ];

    /**
     * Tool types that have no internal parallelism. Declaring `cores: N > 1`
     * on these reserves slots in the budget without benefit (the tool still
     * uses one core) and slows admission of other jobs. `custom` is excluded
     * on purpose: scripts may have their own concurrency the system cannot
     * inspect, so the user is trusted there.
     */
    private const SINGLE_THREADED_TYPES = ['phpmd', 'phpunit', 'phpcpd'];

    /**
     * Map of legacy camelCase keys (deprecated since v3.3, removed in v4.0)
     * to their canonical kebab-case form. Closed list — extending requires
     * a new spec and bumping the deprecation cycle.
     */
    private const DEPRECATED_KEY_MAP = [
        'executablePath'     => 'executable-path',
        'otherArguments'     => 'other-arguments',
        'ignoreErrorsOnExit' => 'ignore-errors-on-exit',
        'failFast'           => 'fail-fast',
    ];

    private string $name;

    private string $type;

    /** @var array<string, mixed> Tool-specific arguments (everything except 'type') */
    private array $config;

    /** @param array<string, mixed> $config */
    public function __construct(string $name, string $type, array $config)
    {
        $this->name = $name;
        $this->type = $type;
        $this->config = $config;
    }

    /**
     * Build from raw config entry. Validates type against the registry.
     *
     * @param string $name Job name (the key in 'jobs' section)
     * @param array<string, mixed> $raw The job definition array
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Validates type, execution mode, custom keys, and tool arguments
     * @SuppressWarnings(PHPMD.NPathComplexity) Each validation check adds an independent early-return branch
     */
    public static function fromArray(
        string $name,
        array $raw,
        ToolRegistry $toolRegistry,
        ValidationResult $result,
        ?JobRegistry $jobRegistry = null
    ): ?self {
        if (!array_key_exists('type', $raw)) {
            $result->addError("Job '$name' is missing the required 'type' key.");
            return null;
        }

        $type = $raw['type'];

        if (!is_string($type)) {
            $result->addError("Job '$name': 'type' must be a string.");
            return null;
        }

        if (
            $type !== 'custom' && !$toolRegistry->isSupported($type)
            && ($jobRegistry === null || !$jobRegistry->isSupported($type))
        ) {
            $result->addError("Job '$name': type '$type' is not a supported tool.");
            return null;
        }

        $normalized = self::normalizeDeprecatedKeys($name, $raw, $result);
        if ($normalized === null) {
            return null;
        }
        $raw = $normalized;

        if ($type === 'custom' && !array_key_exists('script', $raw) && !array_key_exists('executable-path', $raw)) {
            $result->addError("Job '$name': custom jobs require a 'script' or 'executable-path' key.");
            return null;
        }

        $config = $raw;
        unset($config['type']);

        if (array_key_exists('execution', $config)) {
            if (!is_string($config['execution']) || !ExecutionMode::isValid($config['execution'])) {
                $result->addError("Job '$name': 'execution' must be one of: " . implode(', ', ExecutionMode::ALL) . ".");
                return null;
            }
        }

        if ($type === 'custom') {
            self::validateCustomJobKeys($name, $config, $result);
        } elseif ($jobRegistry !== null) {
            self::validateArguments($name, $type, $config, $jobRegistry, $result);
        }

        self::validateCoresKey($name, $type, $config, $result);
        self::validateThresholdKeys($name, $config, $result);
        self::validateMemoryKey($name, $config, $result);

        return new self($name, $type, $config);
    }

    /**
     * Detect and normalize the four legacy camelCase keys (executablePath,
     * otherArguments, ignoreErrorsOnExit, failFast) to their kebab-case canonical
     * form. Each conversion records a structured Deprecation that the JSON v2
     * (and SARIF) output exposes; the user-facing warning string is emitted
     * automatically by ValidationResult::addDeprecation().
     *
     * Returns the normalized array, or null if the same key was declared in
     * both forms simultaneously (an error condition that aborts the job —
     * the user must pick one and remove the other).
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>|null
     */
    private static function normalizeDeprecatedKeys(string $name, array $raw, ValidationResult $result): ?array
    {
        $hasConflict = false;

        foreach (self::DEPRECATED_KEY_MAP as $oldKey => $newKey) {
            $hasOld = array_key_exists($oldKey, $raw);
            $hasNew = array_key_exists($newKey, $raw);

            if ($hasOld && $hasNew) {
                $result->addError(
                    "Job '$name': conflicting keys '$oldKey' and '$newKey'. "
                    . "Use only one (kebab-case form is canonical)."
                );
                $hasConflict = true;
                continue;
            }

            if ($hasOld) {
                $result->addDeprecation(new Deprecation($name, $oldKey, $newKey));
                $raw[$newKey] = $raw[$oldKey];
                unset($raw[$oldKey]);
            }
        }

        return $hasConflict ? null : $raw;
    }

    /**
     * Validate the optional `memory` key in two equivalent forms:
     *
     *  - Short form (`memory: 2000`): positive integer (MB). Acts as warn-above
     *    threshold and, when a `memory-budget` exists at flow level, also as
     *    scheduler reservation.
     *  - Extended form (`memory: { warn-above: ..., fail-above: ... }`):
     *    explicit thresholds, no reservation.
     *
     * @param array<string, mixed> $config
     */
    private static function validateMemoryKey(string $name, array $config, ValidationResult $result): void
    {
        if (!array_key_exists(MemoryThreshold::KEY, $config)) {
            return;
        }

        $value = $config[MemoryThreshold::KEY];

        if (is_int($value)) {
            if ($value < 1) {
                $result->addError("Job '$name': 'memory' must be a positive integer (MB).");
            }
            return;
        }

        if (is_array($value)) {
            MemoryThreshold::fromArray($value, $result, $name);
            return;
        }

        $result->addError(
            "Job '$name': 'memory' must be either a positive integer (MB) or an object "
            . "with 'warn-above'/'fail-above'."
        );
    }

    /**
     * Validate the optional `warn-after` / `fail-after` per-job thresholds.
     * Both must be positive integers (seconds); when both are declared,
     * `warn-after` must be strictly less than `fail-after`.
     *
     * @param array<string, mixed> $config
     */
    private static function validateThresholdKeys(string $name, array $config, ValidationResult $result): void
    {
        $warnAfter = self::extractPositiveInt($name, 'warn-after', $config, $result);
        $failAfter = self::extractPositiveInt($name, 'fail-after', $config, $result);

        if ($warnAfter !== null && $failAfter !== null && $warnAfter >= $failAfter) {
            $result->addError(
                "Job '$name': 'warn-after' ($warnAfter) must be less than 'fail-after' ($failAfter)."
            );
        }

        if (array_key_exists('time-budget', $config)) {
            // BUG-10: hard error so conf:check exits 1. The suggestion stays
            // so the user knows the canonical alternative.
            $result->addError(
                "Job '$name': key 'time-budget' is not valid in jobs; use 'warn-after'/'fail-after' instead."
            );
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function extractPositiveInt(
        string $jobName,
        string $key,
        array $config,
        ValidationResult $result
    ): ?int {
        if (!array_key_exists($key, $config)) {
            return null;
        }

        $value = $config[$key];
        if (!is_int($value) || $value < 1) {
            $result->addError("Job '$jobName': '$key' must be a positive integer (seconds).");
            return null;
        }

        return $value;
    }

    /**
     * Validate the optional `cores` keyword (positive integer) and the tool's
     * native threading flag, which is interchangeable with `cores` on
     * controllable tools (`parallel`, `threads`, `jobs`, `processes`).
     *
     * Rules:
     *  - `cores` declared: must be positive int. Single-threaded tools warn on
     *    `cores > 1`. When the native flag is also declared, `cores` wins and
     *    a conflict warning fires (no positive-int check on the native flag —
     *    it is being overridden, validating it would be redundant noise).
     *  - `cores` absent and a controllable native flag is declared: validate
     *    it as positive int (symmetric with `cores`).
     *
     * @param array<string, mixed> $config
     */
    private static function validateCoresKey(
        string $name,
        string $type,
        array $config,
        ValidationResult $result
    ): void {
        if (!array_key_exists('cores', $config)) {
            self::validateNativeThreadFlagAsCores($name, $type, $config, $result);
            return;
        }

        $cores = $config['cores'];
        if (!is_int($cores) || $cores < 1) {
            $result->addWarning("Job '$name': 'cores' must be a positive integer.");
            return;
        }

        if (in_array($type, self::SINGLE_THREADED_TYPES, true) && $cores > 1) {
            $result->addWarning(
                "Job '$name': '$type' is single-threaded; 'cores' ($cores) "
                . "reserves slots in the budget without benefit. Remove the key "
                . "or set 'cores: 1'."
            );
            return;
        }

        if (!isset(self::THREAD_ARG_KEYS[$type])) {
            return;
        }

        $nativeKey = self::THREAD_ARG_KEYS[$type];
        if (array_key_exists($nativeKey, $config)) {
            $nativeValue = $config[$nativeKey];
            $result->addWarning(
                "Job '$name': 'cores' overrides '$nativeKey' "
                . "(cores=$cores, $nativeKey=$nativeValue)."
            );
        }
    }

    /**
     * When `cores` is absent, the tool's native threading flag acts as
     * implicit cores override at runtime (JobAbstract::extractCoresOverride).
     * Apply the same positive-integer guard `cores` enjoys, so a malformed
     * `parallel: -1` does not silently degrade to "ignored at the allocator,
     * still passed verbatim to the tool".
     *
     * @param array<string, mixed> $config
     */
    private static function validateNativeThreadFlagAsCores(
        string $name,
        string $type,
        array $config,
        ValidationResult $result
    ): void {
        if (!isset(self::THREAD_ARG_KEYS[$type])) {
            return;
        }

        $nativeKey = self::THREAD_ARG_KEYS[$type];
        if (!array_key_exists($nativeKey, $config)) {
            return;
        }

        $value = $config[$nativeKey];
        if (!is_int($value) || $value < 1) {
            $result->addWarning("Job '$name': '$nativeKey' must be a positive integer.");
        }
    }

    /**
     * Validate job arguments against the ARGUMENT_MAP of the target job class.
     *
     * @param array<string, mixed> $config
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Switch covers all ARGUMENT_MAP types
     */
    private static function validateArguments(
        string $name,
        string $type,
        array $config,
        JobRegistry $jobRegistry,
        ValidationResult $result
    ): void {
        $argumentMap = $jobRegistry->getArgumentMap($type);
        if (empty($argumentMap)) {
            return;
        }

        $knownKeys = array_merge(
            array_keys($argumentMap),
            // 'time-budget' is recognised so the unknown-key loop doesn't emit a
            // duplicate warning; the dedicated check in validateTimeBudgetKeys()
            // already rejects it as an error (BUG-10).
            ['executable-path', 'other-arguments', 'ignore-errors-on-exit', 'fail-fast', 'paths', 'rules', 'script', 'accelerable', 'execution', 'executable-prefix', 'cores', 'warn-after', 'fail-after', 'memory', 'time-budget']
        );

        self::reportUnknownAndCliOnlyJobKeys($name, $type, $config, $knownKeys, $result);

        foreach ($argumentMap as $key => $spec) {
            if (!array_key_exists($key, $config)) {
                continue;
            }

            $value = $config[$key];
            $argType = $spec['type'] ?? 'value';

            switch ($argType) {
                case 'boolean':
                    if (!is_bool($value) && !is_int($value)) {
                        $result->addWarning("Job '$name': key '$key' expects a boolean value.");
                    }
                    break;
                case 'paths':
                case 'repeat':
                    if (!is_array($value)) {
                        $result->addWarning("Job '$name': key '$key' expects an array.");
                    }
                    break;
                case 'csv':
                    if (!is_array($value) && !is_string($value)) {
                        $result->addWarning("Job '$name': key '$key' expects an array or string.");
                    }
                    break;
                case 'value':
                case 'key_value':
                    if (!is_string($value) && !is_int($value)) {
                        $result->addWarning("Job '$name': key '$key' expects a string or integer.");
                    }
                    break;
            }
        }

        // Validate common keys not in ARGUMENT_MAP but used by the system
        self::validateCommonKeys($name, $config, $argumentMap, $result);
    }

    /**
     * Validate common config keys (paths, rules) that may not be in the ARGUMENT_MAP
     * but are handled directly by the job's buildCommand().
     *
     * @param array<string, mixed> $config
     * @param array<string, array<string, string>> $argumentMap
     */
    private static function validateCommonKeys(
        string $name,
        array $config,
        array $argumentMap,
        ValidationResult $result
    ): void {
        // Only validate if the key exists in config but NOT already in the ARGUMENT_MAP
        if (array_key_exists('paths', $config) && !array_key_exists('paths', $argumentMap)) {
            if (!is_array($config['paths'])) {
                $result->addWarning("Job '$name': key 'paths' expects an array.");
            }
        }

        if (array_key_exists('rules', $config) && !array_key_exists('rules', $argumentMap)) {
            if (!is_string($config['rules'])) {
                $result->addWarning("Job '$name': key 'rules' expects a string.");
            }
        }
    }

    /**
     * Validate keys for custom jobs (no ARGUMENT_MAP, only known keys).
     *
     * @param array<string, mixed> $config
     */
    private static function validateCustomJobKeys(string $name, array $config, ValidationResult $result): void
    {
        $knownKeys = ['script', 'executable-path', 'other-arguments', 'ignore-errors-on-exit', 'fail-fast', 'paths', 'accelerable', 'execution', 'executable-prefix', 'cores', 'warn-after', 'fail-after', 'memory'];

        foreach (array_keys($config) as $key) {
            if (!in_array($key, $knownKeys, true)) {
                $suggestion = KeySuggestion::suggestionFor((string) $key, $knownKeys);
                $result->addWarning("Job '$name': unknown key '$key' for type 'custom'.{$suggestion}");
            }
        }
    }

    /**
     * Report unknown job keys (with did-you-mean suggestion) and CLI-only
     * keys (`files`, `files-from`, `exclude-pattern`) as hard errors.
     * Extracted from validateArguments() to keep its NPath complexity
     * below the project threshold.
     *
     * @param array<string, mixed> $config
     * @param string[] $knownKeys
     */
    private static function reportUnknownAndCliOnlyJobKeys(
        string $name,
        string $type,
        array $config,
        array $knownKeys,
        ValidationResult $result
    ): void {
        $cliOnlyKeys = ['files', 'files-from', 'exclude-pattern'];
        foreach (array_keys($config) as $key) {
            if (in_array($key, $cliOnlyKeys, true)) {
                $result->addError(
                    "Job '$name': key '$key' is CLI-only and cannot be declared in jobs. "
                    . "Use --$key on the command line instead."
                );
                continue;
            }
            if (!in_array($key, $knownKeys, true)) {
                $suggestion = KeySuggestion::suggestionFor((string) $key, $knownKeys);
                $result->addWarning("Job '$name': unknown key '$key' for type '$type'.{$suggestion}");
            }
        }
    }

    /**
     * Whether this job supports fast mode (path filtering with staged files).
     * Explicit 'accelerable' in config takes precedence; otherwise uses the type's default.
     */
    public function isAccelerable(JobRegistry $jobRegistry): bool
    {
        if (array_key_exists('accelerable', $this->config)) {
            return (bool) $this->config['accelerable'];
        }

        return $jobRegistry->isAccelerable($this->type);
    }

    /**
     * Return a new instance with the given paths (immutable).
     *
     * @param string[] $paths
     */
    public function withPaths(array $paths): self
    {
        $newConfig = $this->config;
        $newConfig['paths'] = $paths;

        return new self($this->name, $this->type, $newConfig);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /** @return array<string, mixed> */
    public function getConfig(): array
    {
        return $this->config;
    }

    /** @return string[] */
    public function getPaths(): array
    {
        return $this->config['paths'] ?? [];
    }

    public function getExecution(): ?string
    {
        return $this->config['execution'] ?? null;
    }

    /**
     * Declared `cores: N` keyword (without inferring from native flags).
     * Returns null when absent or invalid; cross-flow validators use this
     * to check whether a job's reservation exceeds any flow's `processes`
     * budget before runtime.
     */
    public function getCores(): ?int
    {
        $value = $this->config['cores'] ?? null;
        return is_int($value) && $value > 0 ? $value : null;
    }

    public function getWarnAfter(): ?int
    {
        $value = $this->config['warn-after'] ?? null;
        return is_int($value) && $value > 0 ? $value : null;
    }

    public function getFailAfter(): ?int
    {
        $value = $this->config['fail-after'] ?? null;
        return is_int($value) && $value > 0 ? $value : null;
    }

    public function hasThreshold(): bool
    {
        return $this->getWarnAfter() !== null || $this->getFailAfter() !== null;
    }

    /**
     * Resolve the per-job memory threshold from the raw config. Returns null
     * when 'memory' is absent or the value cannot be parsed as a valid
     * threshold. Errors are NOT collected here — they are emitted at parse
     * time by validateMemoryKey().
     */
    public function getMemoryThreshold(): ?MemoryThreshold
    {
        if (!array_key_exists(MemoryThreshold::KEY, $this->config)) {
            return null;
        }

        $value = $this->config[MemoryThreshold::KEY];

        if (is_int($value) && $value > 0) {
            return MemoryThreshold::fromInt($value);
        }

        if (is_array($value)) {
            return MemoryThreshold::fromArray($value, new ValidationResult(), $this->name);
        }

        return null;
    }

    /**
     * Scheduler reservation in MB. Equals the integer value when 'memory' is
     * declared in short form; null when declared in extended form or absent.
     */
    public function getMemoryReserve(): ?int
    {
        $threshold = $this->getMemoryThreshold();
        return $threshold !== null ? $threshold->getReserve() : null;
    }

    public function hasMemoryThreshold(): bool
    {
        return $this->getMemoryThreshold() !== null;
    }
}
