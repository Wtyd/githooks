<?php

declare(strict_types=1);

namespace Tests\Unit\History;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\History\Sparkline;

/**
 * FEAT-5 · ASCII sparkline. Covers the degenerate classes (empty / single /
 * flat) and that a varied series produces relief spanning the low and high
 * block characters.
 */
class SparklineTest extends UnitTestCase
{
    /** @test */
    public function empty_series_renders_an_empty_string(): void
    {
        $this->assertSame('', Sparkline::render([]));
    }

    /** @test */
    public function single_value_renders_one_mid_block(): void
    {
        $this->assertSame('▄', Sparkline::render([4.2]));
    }

    /** @test */
    public function flat_series_renders_all_mid_blocks(): void
    {
        $this->assertSame('▄▄▄', Sparkline::render([5.0, 5.0, 5.0]));
    }

    /** @test */
    public function rising_series_spans_lowest_to_highest_block(): void
    {
        $line = Sparkline::render([0.0, 1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0]);

        $this->assertSame(8, mb_strlen($line));
        $this->assertStringStartsWith('▁', $line); // min → lowest block
        $this->assertStringEndsWith('█', $line);    // max → highest block
    }

    /** @test */
    public function min_and_max_map_to_the_boundary_blocks(): void
    {
        $line = Sparkline::render([10.0, 20.0]);

        $this->assertSame('▁█', $line);
    }
}
