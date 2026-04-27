<?php

declare(strict_types=1);

namespace Tests\Unit\Configuration;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\AllocatorStrategy;

class AllocatorStrategyTest extends TestCase
{
    /** @test */
    public function fifo_is_valid(): void
    {
        $this->assertTrue(AllocatorStrategy::isValid(AllocatorStrategy::FIFO));
        $this->assertTrue(AllocatorStrategy::isValid('fifo'));
    }

    /** @test */
    public function greedy_is_valid(): void
    {
        $this->assertTrue(AllocatorStrategy::isValid(AllocatorStrategy::GREEDY));
        $this->assertTrue(AllocatorStrategy::isValid('greedy'));
    }

    /** @test */
    public function unknown_strategy_is_invalid(): void
    {
        $this->assertFalse(AllocatorStrategy::isValid('random'));
        $this->assertFalse(AllocatorStrategy::isValid('FIFO'));
        $this->assertFalse(AllocatorStrategy::isValid(''));
    }

    /** @test */
    public function all_contains_only_known_strategies(): void
    {
        $this->assertSame(['fifo', 'greedy'], AllocatorStrategy::ALL);
    }
}
