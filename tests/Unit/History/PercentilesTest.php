<?php

declare(strict_types=1);

namespace Tests\Unit\History;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\History\Percentiles;

/**
 * FEAT-5 · summary statistics. Covers nearest-rank percentiles, the flat
 * series, and the trend windows (none / single / two halves), matching the
 * factor table for `profile` history states.
 */
class PercentilesTest extends UnitTestCase
{
    /** @test */
    public function single_value_collapses_min_p50_p95_max_and_has_no_trend(): void
    {
        $stats = Percentiles::compute([4.0]);

        $this->assertSame(4.0, $stats['min']);
        $this->assertSame(4.0, $stats['p50']);
        $this->assertSame(4.0, $stats['p95']);
        $this->assertSame(4.0, $stats['max']);
        $this->assertNull($stats['trend']); // window < 1 → n/a
    }

    /** @test */
    public function flat_series_reports_flat_trend(): void
    {
        $stats = Percentiles::compute([5.0, 5.0, 5.0, 5.0]);

        $this->assertSame(5.0, $stats['min']);
        $this->assertSame(5.0, $stats['max']);
        $this->assertNotNull($stats['trend']);
        $this->assertSame('flat', $stats['trend']['direction']);
        $this->assertSame(0.0, $stats['trend']['percent']);
    }

    /** @test */
    public function nearest_rank_percentiles_over_a_known_series(): void
    {
        // 1..10 ascending. nearest-rank: p50 → ceil(.5*10)=5 → index4 = 5; p95 → ceil(9.5)=10 → index9 = 10.
        $stats = Percentiles::compute([1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0, 9.0, 10.0]);

        $this->assertSame(1.0, $stats['min']);
        $this->assertSame(5.0, $stats['p50']);
        $this->assertSame(10.0, $stats['p95']);
        $this->assertSame(10.0, $stats['max']);
    }

    /** @test */
    public function percentiles_are_order_independent(): void
    {
        $shuffled = Percentiles::compute([5.0, 1.0, 9.0, 3.0, 7.0]);

        $this->assertSame(1.0, $shuffled['min']);
        $this->assertSame(9.0, $shuffled['max']);
        $this->assertSame(5.0, $shuffled['p50']); // ceil(.5*5)=3 → index2 of sorted = 5
    }

    /** @test */
    public function trend_compares_recent_half_against_previous_half(): void
    {
        // window = floor(4/2)=2. recent=[3,4] mean 3.5; previous=[1,2] mean 1.5 → +133.3%.
        $stats = Percentiles::compute([1.0, 2.0, 3.0, 4.0]);

        $this->assertNotNull($stats['trend']);
        $this->assertSame('up', $stats['trend']['direction']);
        $this->assertSame(2, $stats['trend']['window']);
        $this->assertSame(133.3, $stats['trend']['percent']);
    }

    /** @test */
    public function trend_with_two_values_uses_a_single_element_window(): void
    {
        $stats = Percentiles::compute([10.0, 5.0]);

        $this->assertNotNull($stats['trend']);
        $this->assertSame('down', $stats['trend']['direction']);
        $this->assertSame(1, $stats['trend']['window']);
        $this->assertSame(-50.0, $stats['trend']['percent']);
    }

    /** @test */
    public function trend_percent_is_null_when_previous_mean_is_zero(): void
    {
        $stats = Percentiles::compute([0.0, 0.0, 4.0, 6.0]);

        $this->assertNotNull($stats['trend']);
        $this->assertSame('up', $stats['trend']['direction']);
        $this->assertNull($stats['trend']['percent']); // division by zero avoided
    }
}
