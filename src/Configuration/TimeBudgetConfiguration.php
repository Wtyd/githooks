<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Configuration;

/**
 * Time-budget value object — the `time-budget` subsection of `flows.options`
 * and `flows.<name>.options`. Watches the SUM of job durations executed in
 * the flow against the declared `warn-after` / `fail-after` (seconds).
 *
 * Independent from per-job thresholds: the cascade flow→flows→default applies
 * to this VO as a whole; declaring `time-budget` does NOT propagate values to
 * individual jobs.
 */
final class TimeBudgetConfiguration
{
    public const KEY = 'time-budget';

    public const WARN_AFTER_KEY = 'warn-after';

    public const FAIL_AFTER_KEY = 'fail-after';

    private ?int $warnAfter;

    private ?int $failAfter;

    public function __construct(?int $warnAfter, ?int $failAfter)
    {
        $this->warnAfter = $warnAfter;
        $this->failAfter = $failAfter;
    }

    /**
     * Build from raw config entry ('time-budget' subsection).
     *
     * Returns null when the raw value is absent (caller already filtered) or
     * cannot be parsed at all (non-array). Errors and warnings are collected
     * in the ValidationResult.
     *
     * @param mixed $raw
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Validates each key independently and emits per-key feedback.
     */
    public static function fromArray($raw, ValidationResult $result): ?self
    {
        if (!is_array($raw)) {
            $result->addError("'" . self::KEY . "' must be an associative array.");
            return null;
        }

        $warnAfter = self::parsePositiveInt(self::WARN_AFTER_KEY, $raw, $result);
        $failAfter = self::parsePositiveInt(self::FAIL_AFTER_KEY, $raw, $result);

        if ($warnAfter !== null && $failAfter !== null && $warnAfter >= $failAfter) {
            $result->addError(
                "'" . self::WARN_AFTER_KEY . "' ($warnAfter) must be less than '"
                . self::FAIL_AFTER_KEY . "' ($failAfter)."
            );
        }

        $knownKeys = [self::WARN_AFTER_KEY, self::FAIL_AFTER_KEY];
        foreach (array_keys($raw) as $key) {
            if (!in_array($key, $knownKeys, true)) {
                $suggestion = self::suggestKey((string) $key, $knownKeys);
                $hint = $suggestion !== null ? " (did you mean '$suggestion'?)" : '';
                $result->addWarning("Unknown key '$key' in '" . self::KEY . "'$hint.");
            }
        }

        if ($warnAfter === null && $failAfter === null) {
            return null;
        }

        return new self($warnAfter, $failAfter);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function parsePositiveInt(string $key, array $raw, ValidationResult $result): ?int
    {
        if (!array_key_exists($key, $raw)) {
            return null;
        }

        $value = $raw[$key];
        if (!is_int($value) || $value < 1) {
            $result->addError("'$key' must be a positive integer (seconds).");
            return null;
        }

        return $value;
    }

    /**
     * @param string[] $candidates
     */
    private static function suggestKey(string $needle, array $candidates): ?string
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;
        foreach ($candidates as $candidate) {
            $distance = levenshtein($needle, $candidate);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $candidate;
            }
        }
        return ($best !== null && $bestDistance <= 3) ? $best : null;
    }

    public function getWarnAfter(): ?int
    {
        return $this->warnAfter;
    }

    public function getFailAfter(): ?int
    {
        return $this->failAfter;
    }

    public function isEmpty(): bool
    {
        return $this->warnAfter === null && $this->failAfter === null;
    }
}
