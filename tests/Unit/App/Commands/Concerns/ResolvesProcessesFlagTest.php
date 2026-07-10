<?php

declare(strict_types=1);

namespace Tests\Unit\App\Commands\Concerns;

use Tests\Utils\TestCase\UnitTestCase;

class ResolvesProcessesFlagTest extends UnitTestCase
{
    /** @test */
    public function it_returns_null_when_flag_absent(): void
    {
        $double = new ResolvesProcessesFlagCommandDouble();

        $this->assertNull($double->call());
        $this->assertSame([], $double->errLines);
    }

    /** @test */
    public function it_returns_null_when_flag_empty(): void
    {
        $double = new ResolvesProcessesFlagCommandDouble();
        $double->options = ['processes' => ''];

        $this->assertNull($double->call());
        $this->assertSame([], $double->errLines);
    }

    /** @test */
    public function it_parses_a_valid_positive_integer(): void
    {
        $double = new ResolvesProcessesFlagCommandDouble();
        $double->options = ['processes' => '4'];

        $this->assertSame(4, $double->call());
        $this->assertSame([], $double->errLines);
    }

    /**
     * CLI = best-effort: an invalid value warns on stderr and is ignored
     * (the cascade falls back to config/default), never aborting — the mirror
     * of the config path's hard error.
     *
     * @test
     * @dataProvider invalidValues
     */
    public function invalid_value_is_rejected_with_warning(string $value): void
    {
        $double = new ResolvesProcessesFlagCommandDouble();
        $double->options = ['processes' => $value];

        $this->assertNull($double->call());
        $this->assertNotEmpty($double->errLines);
    }

    /** @return array<string, array{string}> */
    public function invalidValues(): array
    {
        return [
            'zero'        => ['0'],
            'negative'    => ['-1'],
            'non-numeric' => ['many'],
            'float'       => ['1.5'],
        ];
    }

    /** @test */
    public function warning_message_is_exact(): void
    {
        $double = new ResolvesProcessesFlagCommandDouble();
        $double->options = ['processes' => '0'];

        $this->assertNull($double->call());
        $this->assertSame(
            ["<comment>Warning:</comment> --processes expects a positive integer; got '0'. Ignoring."],
            $double->errLines
        );
    }
}
