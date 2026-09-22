<?php

declare(strict_types=1);

namespace Tests\Unit\History;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\History\RunHistoryStore;

/**
 * Retention window of the run history, driven directly rather than through
 * `rotate()`. Going through the store would leave the ordering untested: the
 * listing it prunes comes from `Storage::files()`, whose order depends on how
 * the filesystem enumerates the directory, so a test cannot force the
 * out-of-order case that the `sort()` exists for.
 */
class RunHistoryRotationTest extends UnitTestCase
{
    /**
     * @test
     *
     * The listing arrives newest-first here. Without re-sorting, the slice
     * takes the head of the list — the NEWEST runs — and the history keeps
     * the ones it was supposed to discard.
     */
    public function stale_files_are_the_oldest_ones_whatever_order_the_listing_arrives_in(): void
    {
        $unordered = [
            'history/20260401-100000-qa.json',
            'history/20260101-100000-qa.json',
            'history/20260301-100000-qa.json',
            'history/20260201-100000-qa.json',
        ];

        $this->assertSame(
            [
                'history/20260101-100000-qa.json',
                'history/20260201-100000-qa.json',
            ],
            RunHistoryStore::staleFiles($unordered, 2)
        );
    }

    /**
     * @test
     * @dataProvider retentionBoundaryCases
     *
     * Boundary of `count($files) <= $historySize`: nothing is dropped while
     * the history fits, and exactly one file goes the moment it overflows.
     *
     * @param string[] $files
     * @param string[] $expected
     */
    public function nothing_is_dropped_until_the_window_overflows(array $files, int $historySize, array $expected): void
    {
        $this->assertSame($expected, RunHistoryStore::staleFiles($files, $historySize));
    }

    /**
     * @return array<string, array{0: string[], 1: int, 2: string[]}>
     */
    public function retentionBoundaryCases(): array
    {
        $three = ['a/1.json', 'a/2.json', 'a/3.json'];

        return [
            'below the window'  => [['a/1.json'], 3, []],
            'exactly the window' => [$three, 3, []],
            'one over the window' => [$three, 2, ['a/1.json']],
            'window of zero drops everything' => [$three, 0, $three],
        ];
    }
}
