<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Configuration;

/**
 * Per-job memory threshold value object. Declared as the value of the `memory`
 * key inside a `jobs.<name>` entry. Two equivalent forms:
 *
 *  - **Short form** (`memory: 2000`): integer MB. Equivalent to a single
 *    `warn-above` threshold; when a `memory-budget` is declared at flow level,
 *    the same integer is also used by the scheduler as a memory reservation
 *    for the job (REQ-007, REQ-009).
 *  - **Extended form** (`memory: { warn-above: 2000, fail-above: 2500 }`):
 *    explicit thresholds with no scheduler reservation. Allows declaring
 *    fail-above per job, only warn-above, or both.
 */
final class MemoryThreshold
{
    public const KEY = 'memory';

    public const WARN_ABOVE_KEY = 'warn-above';

    public const FAIL_ABOVE_KEY = 'fail-above';

    private ?int $warnAbove;

    private ?int $failAbove;

    private bool $shortForm;

    private function __construct(?int $warnAbove, ?int $failAbove, bool $shortForm)
    {
        $this->warnAbove = $warnAbove;
        $this->failAbove = $failAbove;
        $this->shortForm = $shortForm;
    }

    /**
     * Short form: a positive integer (MB). Used as both threshold and
     * scheduler reservation when a `memory-budget` exists at flow level.
     */
    public static function fromInt(int $value): self
    {
        return new self($value, null, true);
    }

    /**
     * Extended form: an associative array with `warn-above` and/or `fail-above`.
     * Returns null when the array is empty (no thresholds). Errors and warnings
     * are collected in the ValidationResult.
     *
     * @param array<string, mixed> $raw
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Validates each key independently.
     */
    public static function fromArray(array $raw, ValidationResult $result, string $jobName): ?self
    {
        $warnAbove = self::parsePositiveInt($jobName, self::WARN_ABOVE_KEY, $raw, $result);
        $failAbove = self::parsePositiveInt($jobName, self::FAIL_ABOVE_KEY, $raw, $result);

        if ($warnAbove !== null && $failAbove !== null && $warnAbove >= $failAbove) {
            $result->addError(
                "Job '$jobName': '" . self::WARN_ABOVE_KEY . "' ($warnAbove) must be less than '"
                . self::FAIL_ABOVE_KEY . "' ($failAbove) in 'memory'."
            );
        }

        $knownKeys = [self::WARN_ABOVE_KEY, self::FAIL_ABOVE_KEY];
        foreach (array_keys($raw) as $key) {
            if (!in_array($key, $knownKeys, true)) {
                $suggestion = self::suggestKey((string) $key, $knownKeys);
                $hint = $suggestion !== null ? " (did you mean '$suggestion'?)" : '';
                $result->addWarning(
                    "Job '$jobName': unknown key '$key' in '" . self::KEY . "'$hint."
                );
            }
        }

        if ($warnAbove === null && $failAbove === null) {
            return null;
        }

        return new self($warnAbove, $failAbove, false);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function parsePositiveInt(
        string $jobName,
        string $key,
        array $raw,
        ValidationResult $result
    ): ?int {
        if (!array_key_exists($key, $raw)) {
            return null;
        }

        $value = $raw[$key];
        if (!is_int($value) || $value < 1) {
            $result->addError("Job '$jobName': '$key' must be a positive integer (MB) in 'memory'.");
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
     * Whether the threshold was declared in short form (single integer).
     * Only short-form thresholds participate in the 2D scheduler reservation.
     */
    public function isShortForm(): bool
    {
        return $this->shortForm;
    }

    /**
     * The scheduler reservation in MB. Equals the integer value when the
     * threshold was declared in short form; null otherwise.
     */
    public function getReserve(): ?int
    {
        return $this->shortForm ? $this->warnAbove : null;
    }
}
