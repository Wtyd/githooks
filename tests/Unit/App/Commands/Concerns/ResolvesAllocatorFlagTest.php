<?php

declare(strict_types=1);

namespace Tests\Unit\App\Commands\Concerns;

use Tests\Utils\TestCase\UnitTestCase;

class ResolvesAllocatorFlagTest extends UnitTestCase
{
    /** @test */
    public function it_returns_null_when_flag_absent(): void
    {
        $double = new ResolvesAllocatorFlagCommandDouble();

        $this->assertNull($double->call());
        $this->assertSame([], $double->errLines);
    }

    /** @test */
    public function it_returns_null_when_flag_empty(): void
    {
        $double = new ResolvesAllocatorFlagCommandDouble();
        $double->options = ['allocator' => ''];

        $this->assertNull($double->call());
        $this->assertSame([], $double->errLines);
    }

    /** @test */
    public function it_parses_fifo(): void
    {
        $double = new ResolvesAllocatorFlagCommandDouble();
        $double->options = ['allocator' => 'fifo'];

        $this->assertSame('fifo', $double->call());
        $this->assertSame([], $double->errLines);
    }

    /** @test */
    public function it_parses_greedy(): void
    {
        $double = new ResolvesAllocatorFlagCommandDouble();
        $double->options = ['allocator' => 'greedy'];

        $this->assertSame('greedy', $double->call());
        $this->assertSame([], $double->errLines);
    }

    /** @test */
    public function invalid_value_is_rejected_with_warning(): void
    {
        $double = new ResolvesAllocatorFlagCommandDouble();
        $double->options = ['allocator' => 'random'];

        $this->assertNull($double->call());
        $this->assertSame(
            ["<comment>Warning:</comment> --allocator expects one of: fifo, greedy (got 'random'). Ignoring."],
            $double->errLines
        );
    }

    /** @test */
    public function uppercase_is_rejected(): void
    {
        $double = new ResolvesAllocatorFlagCommandDouble();
        $double->options = ['allocator' => 'FIFO'];

        $this->assertNull($double->call());
        $this->assertNotEmpty($double->errLines);
    }
}
