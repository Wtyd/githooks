<?php

declare(strict_types=1);

namespace Tests\Unit\App\Commands\Concerns;

use PHPUnit\Framework\TestCase;

class ResolvesStatsFlagTest extends TestCase
{
    /** @test */
    public function it_returns_null_when_flag_absent(): void
    {
        $double = new ResolvesStatsFlagCommandDouble();

        $this->assertNull($double->call());
    }

    /** @test */
    public function it_returns_true_when_flag_present_and_truthy(): void
    {
        $double = new ResolvesStatsFlagCommandDouble();
        $double->options = ['stats' => true];

        $this->assertTrue($double->call());
    }

    /** @test */
    public function it_returns_null_when_flag_present_but_falsy(): void
    {
        $double = new ResolvesStatsFlagCommandDouble();
        $double->options = ['stats' => false];

        // False does not override the cascade — let lower layers decide.
        $this->assertNull($double->call());
    }
}
