<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Configuration;

/**
 * A reference from a flow to a job, with optional file-based admission rules.
 *
 * In v3.4 (FEAT-1) flow entries may be either a plain job name (string) or an
 * object carrying `only-files` / `exclude-files` glob lists. The lists act as
 * a binary admission decision (skip vs run) evaluated in `FlowPreparer` before
 * the accelerable check — they are orthogonal to input filtering (`paths`).
 *
 * Override semantics: `null` cancels an inherited rule from githooks.php,
 * `[]` is a validation error. See FEAT-1-only-files.md for the full table.
 */
class JobRef
{
    private string $target;

    /** @var string[]|null null = no rule (or rule cancelled via local override) */
    private ?array $onlyFiles;

    /** @var string[]|null */
    private ?array $excludeFiles;

    /** @var string[] FEAT-3: ordered job names this entry depends on within the same flow */
    private array $needs;

    /**
     * @param string[]|null $onlyFiles
     * @param string[]|null $excludeFiles
     * @param string[] $needs
     */
    public function __construct(
        string $target,
        ?array $onlyFiles = null,
        ?array $excludeFiles = null,
        array $needs = []
    ) {
        $this->target = $target;
        $this->onlyFiles = $onlyFiles;
        $this->excludeFiles = $excludeFiles;
        $this->needs = $needs;
    }

    public static function fromString(string $ref): self
    {
        return new self($ref);
    }

    /**
     * Build from an extended object form.
     *
     * @param array<string, mixed> $raw e.g. ['job' => 'tests_a', 'only-files' => ['src/A/**']]
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Validates target plus two optional rule keys
     * @SuppressWarnings(PHPMD.NPathComplexity) Each rule adds an independent validation branch
     */
    public static function fromArray(array $raw, ValidationResult $result, string $flowName): ?self
    {
        $target = $raw['job'] ?? null;

        if (!is_string($target) || $target === '') {
            $result->addError("Flow '$flowName': job ref must have a 'job' key with a string value.");
            return null;
        }

        $onlyFiles = self::parseRule($raw, 'only-files', $flowName, $target, $result);
        if ($onlyFiles === false) {
            return null;
        }

        $excludeFiles = self::parseRule($raw, 'exclude-files', $flowName, $target, $result);
        if ($excludeFiles === false) {
            return null;
        }

        $needs = self::parseNeeds($raw, $flowName, $target, $result);
        if ($needs === false) {
            return null;
        }

        self::warnUnknownKeys($raw, $flowName, $target, $result);

        return new self($target, $onlyFiles, $excludeFiles, $needs);
    }

    /**
     * Parse the optional `needs` attribute (FEAT-3). Returns:
     *   - false on validation error (caller aborts)
     *   - string[] (possibly empty when key absent or `null`)
     *
     * @param array<string, mixed> $raw
     * @return string[]|false
     */
    private static function parseNeeds(
        array $raw,
        string $flowName,
        string $target,
        ValidationResult $result
    ) {
        if (!array_key_exists('needs', $raw) || $raw['needs'] === null) {
            return [];
        }

        $value = $raw['needs'];
        if (is_string($value)) {
            $value = [$value];
        }
        if (!is_array($value)) {
            $result->addError(
                "Flow '$flowName' job ref '$target': 'needs' must be a string, array of strings, or null."
            );
            return false;
        }
        if (empty($value)) {
            $result->addError(
                "Flow '$flowName' job ref '$target': 'needs' must not be empty. Use null to disable an inherited rule."
            );
            return false;
        }

        return self::validateJobNameList($value, $flowName, $target, $result);
    }

    /**
     * @param mixed[] $value
     * @return string[]|false
     */
    private static function validateJobNameList(
        array $value,
        string $flowName,
        string $target,
        ValidationResult $result
    ) {
        $needs = [];
        foreach ($value as $name) {
            if (!is_string($name)) {
                $result->addError(
                    "Flow '$flowName' job ref '$target': 'needs' must be a string, array of strings, or null."
                );
                return false;
            }
            if ($name === '') {
                $result->addError(
                    "Flow '$flowName' job ref '$target': 'needs' contains an empty job name."
                );
                return false;
            }
            if (in_array($name, $needs, true)) {
                $result->addError(
                    "Flow '$flowName' job ref '$target': 'needs' contains duplicate job name '$name'."
                );
                return false;
            }
            $needs[] = $name;
        }
        return $needs;
    }

    /**
     * Parse one rule (only-files or exclude-files). Detection uses array_key_exists
     * so `null` is distinguishable from "absent" (D2). Returns:
     *   - false on validation error (caller aborts with $result holding the error)
     *   - null when the key is absent or declared as null (sentinel for "no rule")
     *   - string[] otherwise
     *
     * @param array<string, mixed> $raw
     * @return string[]|null|false
     */
    private static function parseRule(
        array $raw,
        string $key,
        string $flowName,
        string $target,
        ValidationResult $result
    ) {
        if (!array_key_exists($key, $raw) || $raw[$key] === null) {
            return null;
        }

        $value = $raw[$key];
        if (is_string($value)) {
            $value = [$value];
        }

        if (!is_array($value)) {
            $result->addError(
                "Flow '$flowName' job ref '$target': '$key' must be a string, array of strings, or null."
            );
            return false;
        }

        if (empty($value)) {
            $result->addError(
                "Flow '$flowName' job ref '$target': '$key' must not be empty. Use null to disable an inherited rule."
            );
            return false;
        }

        return self::validatePatternList($value, $key, $flowName, $target, $result);
    }

    /**
     * @param mixed[] $value
     * @return string[]|false
     */
    private static function validatePatternList(
        array $value,
        string $key,
        string $flowName,
        string $target,
        ValidationResult $result
    ) {
        $patterns = [];
        foreach ($value as $pattern) {
            if (!is_string($pattern)) {
                $result->addError(
                    "Flow '$flowName' job ref '$target': '$key' must be a string, array of strings, or null."
                );
                return false;
            }
            if ($pattern === '') {
                $result->addError(
                    "Flow '$flowName' job ref '$target': '$key' contains an empty pattern."
                );
                return false;
            }
            if (in_array($pattern, $patterns, true)) {
                $result->addError(
                    "Flow '$flowName' job ref '$target': '$key' contains duplicate pattern '$pattern'."
                );
                return false;
            }
            $patterns[] = $pattern;
        }

        return $patterns;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function warnUnknownKeys(array $raw, string $flowName, string $target, ValidationResult $result): void
    {
        $knownKeys = ['job', 'only-files', 'exclude-files', 'needs'];
        foreach (array_keys($raw) as $key) {
            if (!in_array($key, $knownKeys, true)) {
                $suggestion = KeySuggestion::suggestionFor((string) $key, $knownKeys);
                $result->addWarning(
                    "Flow '$flowName' job ref '$target': unknown key '$key'.{$suggestion}"
                );
            }
        }
    }

    public function getTarget(): string
    {
        return $this->target;
    }

    /** @return string[]|null */
    public function getOnlyFiles(): ?array
    {
        return $this->onlyFiles;
    }

    /** @return string[]|null */
    public function getExcludeFiles(): ?array
    {
        return $this->excludeFiles;
    }

    public function hasAdmissionRules(): bool
    {
        return $this->onlyFiles !== null || $this->excludeFiles !== null;
    }

    /**
     * FEAT-3: ordered job names this entry depends on within the same flow.
     * Empty array when no dependencies are declared.
     *
     * @return string[]
     */
    public function getNeeds(): array
    {
        return $this->needs;
    }
}
