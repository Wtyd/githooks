<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\History;

/**
 * Summary statistics over a series of numeric values (FEAT-5): min, p50, p95,
 * max and a trend comparing the most recent half against the previous half.
 *
 * Percentiles use the nearest-rank method (deterministic, no interpolation).
 * The caller guarantees a non-empty series.
 */
class Percentiles
{
    /**
     * @param float[] $values Chronological order (oldest first).
     * @return array{min: float, p50: float, p95: float, max: float, trend: array{direction: string, percent: float|null, window: int}|null}
     */
    public static function compute(array $values): array
    {
        $sorted = $values;
        sort($sorted);
        $count = count($sorted);

        return [
            'min'   => $sorted[0],
            'p50'   => self::nearestRank($sorted, 50),
            'p95'   => self::nearestRank($sorted, 95),
            'max'   => $sorted[$count - 1],
            'trend' => self::trend($values),
        ];
    }

    /**
     * @param float[] $sorted Ascending.
     */
    private static function nearestRank(array $sorted, int $percentile): float
    {
        $count = count($sorted);
        $rank = (int) ceil($percentile / 100 * $count);
        $index = max(0, min($count - 1, $rank - 1));
        return $sorted[$index];
    }

    /**
     * Compare the mean of the last N values against the mean of the N before
     * them, where N = floor(count/2). Returns null when there is no full
     * previous window (fewer than 2 values).
     *
     * @param float[] $values Chronological order (oldest first).
     * @return array{direction: string, percent: float|null, window: int}|null
     */
    private static function trend(array $values): ?array
    {
        $count = count($values);
        $window = intdiv($count, 2);
        if ($window < 1) {
            return null;
        }

        $recent = array_slice($values, $count - $window, $window);
        $previous = array_slice($values, $count - 2 * $window, $window);

        $recentMean = array_sum($recent) / $window;
        $previousMean = array_sum($previous) / $window;
        $delta = $recentMean - $previousMean;

        $direction = 'flat';
        if ($delta > 0.0) {
            $direction = 'up';
        } elseif ($delta < 0.0) {
            $direction = 'down';
        }

        // Relative change is undefined when the previous mean is zero; report
        // the direction without a percentage rather than dividing by zero.
        $percent = $previousMean != 0.0 ? round($delta / $previousMean * 100, 1) : null;

        return ['direction' => $direction, 'percent' => $percent, 'window' => $window];
    }
}
