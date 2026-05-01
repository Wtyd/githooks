<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Configuration;

/**
 * Suggest the closest known key when the user types a typo. Uses Levenshtein
 * distance with a max threshold of 2 — beyond that the candidate is unrelated
 * and a guess would mislead more than help.
 *
 * Returns " Did you mean '<canonical>'?" with leading space when a suggestion
 * applies, or '' when no key is close enough. Callers append the result to the
 * "Unknown ..." sentence so messages read naturally either way.
 */
final class KeySuggestion
{
    private const MAX_DISTANCE = 2;

    /**
     * @param string[] $known canonical keys to compare against
     */
    public static function suggestionFor(string $unknown, array $known): string
    {
        if ($unknown === '' || $known === []) {
            return '';
        }

        $best = null;
        $bestDistance = self::MAX_DISTANCE + 1;
        foreach ($known as $candidate) {
            $distance = levenshtein($unknown, $candidate);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $candidate;
            }
        }

        if ($best === null || $bestDistance === 0 || $bestDistance > self::MAX_DISTANCE) {
            return '';
        }

        return " Did you mean '$best'?";
    }
}
