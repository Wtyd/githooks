<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Configuration;

/**
 * Memory-budget value object — the `memory-budget` subsection of `flows.options`
 * and `flows.<name>.options`. Watches the simultaneous PEAK of RSS across all
 * jobs in flight against the declared `warn-above` / `fail-above` (MB).
 *
 * Independent from per-job memory thresholds: the cascade flow→flows→default
 * applies to this VO as a whole; declaring `memory-budget` does NOT propagate
 * values to individual jobs.
 */
final class MemoryBudgetConfiguration
{
    public const KEY = 'memory-budget';

    public const WARN_ABOVE_KEY = 'warn-above';

    public const FAIL_ABOVE_KEY = 'fail-above';

    private ?int $warnAbove;

    private ?int $failAbove;

    public function __construct(?int $warnAbove, ?int $failAbove)
    {
        $this->warnAbove = $warnAbove;
        $this->failAbove = $failAbove;
    }

    /**
     * Build from raw config entry ('memory-budget' subsection).
     *
     * Returns null when the raw value is absent or has no positive thresholds.
     * Errors and warnings are collected in the ValidationResult.
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

        $warnAbove = self::parsePositiveInt(self::WARN_ABOVE_KEY, $raw, $result);
        $failAbove = self::parsePositiveInt(self::FAIL_ABOVE_KEY, $raw, $result);

        if ($warnAbove !== null && $failAbove !== null && $warnAbove >= $failAbove) {
            $result->addError(
                "'" . self::WARN_ABOVE_KEY . "' ($warnAbove) must be less than '"
                . self::FAIL_ABOVE_KEY . "' ($failAbove)."
            );
        }

        $knownKeys = [self::WARN_ABOVE_KEY, self::FAIL_ABOVE_KEY];
        foreach (array_keys($raw) as $key) {
            if (!in_array($key, $knownKeys, true)) {
                $suggestion = self::suggestKey((string) $key, $knownKeys);
                $hint = $suggestion !== null ? " (did you mean '$suggestion'?)" : '';
                $result->addWarning("Unknown key '$key' in '" . self::KEY . "'$hint.");
            }
        }

        if ($warnAbove === null && $failAbove === null) {
            return null;
        }

        return new self($warnAbove, $failAbove);
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
            $result->addError("'$key' must be a positive integer (MB).");
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

    public function getWarnAbove(): ?int
    {
        return $this->warnAbove;
    }

    public function getFailAbove(): ?int
    {
        return $this->failAbove;
    }

    /**
     * Reference value for 2D bin-packing admission. Prefer warn-above as the
     * conservative ceiling; fall back to fail-above when only that is declared.
     */
    public function getBinPackingReference(): ?int
    {
        return $this->warnAbove ?? $this->failAbove;
    }

    public function isEmpty(): bool
    {
        return $this->warnAbove === null && $this->failAbove === null;
    }
}
