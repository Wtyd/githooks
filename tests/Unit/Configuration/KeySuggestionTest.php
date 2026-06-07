<?php

declare(strict_types=1);

namespace Tests\Unit\Configuration;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Configuration\KeySuggestion;

class KeySuggestionTest extends UnitTestCase
{
    /**
     * Decision table for `suggestionFor(unknown, known)`.
     *
     * Factors:
     *   - Levenshtein distance: 0 (exact), 1, 2, ≥ 3.
     *   - known list: empty, single, multiple.
     *   - unknown: empty string, real typo.
     *
     * Boundaries: distance == 2 (admitted, last good case), distance == 3
     * (rejected, first bad case).
     *
     * @test
     * @dataProvider cases
     */
    public function suggestion_respects_decision_table(string $unknown, array $known, string $expected): void
    {
        $this->assertSame($expected, KeySuggestion::suggestionFor($unknown, $known));
    }

    public function cases(): array
    {
        return [
            // Distance 1 — typo dropping a letter.
            "distance=1, single match"           => ['memry-budget', ['memory-budget', 'time-budget'], " Did you mean 'memory-budget'?"],
            // Distance 2 — typo with two character changes (boundary, admitted).
            "distance=2, boundary admitted"      => ['memori-budget', ['memory-budget'], " Did you mean 'memory-budget'?"],
            // Distance 3 — too far to be a confident suggestion.
            "distance=3, boundary rejected"      => ['memorz-budgte', ['memory-budget'], ''],
            // Distance 0 — already canonical, no suggestion.
            "distance=0, exact match"            => ['memory-budget', ['memory-budget'], ''],
            // Picks the closest among multiple.
            "multiple known, picks closest"      => ['fals-fast', ['fail-fast', 'memory-budget', 'processes'], " Did you mean 'fail-fast'?"],
            // Empty unknown.
            "empty unknown"                      => ['', ['fail-fast'], ''],
            // Empty known.
            "empty known"                        => ['fail-fast', [], ''],
            // Completely unrelated string.
            "unrelated unknown, no suggestion"   => ['xyz', ['memory-budget', 'time-budget', 'processes'], ''],
            // Empty unknown WITH a short candidate within MAX_DISTANCE — kills the
            // LogicalOr→And mutation (line 25) and the matching ReturnRemoval
            // (line 26): the existing "empty unknown" case uses 'fail-fast' (length 9)
            // so levenshtein('', 'fail-fast')=9 > MAX_DISTANCE and the mutant still
            // returns '' through the bestDistance check. With known=['x'],
            // levenshtein('', 'x')=1 ≤ MAX_DISTANCE, so the mutant falls through
            // and emits a (wrong) suggestion.
            "empty unknown with short known"     => ['', ['x'], ''],
            // Two candidates at distance=1 — kills the LessThan→LessThanOrEqual
            // mutation (line 33). Original picks the FIRST match in tie; mutant
            // picks the LAST. The two candidates differ in a single character so
            // the difference is unambiguous.
            "tie at distance=1, first wins"      => ['foonar', ['foobar', 'foozar'], " Did you mean 'foobar'?"],
        ];
    }
}
