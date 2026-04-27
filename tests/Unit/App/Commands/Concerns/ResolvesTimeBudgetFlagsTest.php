<?php

declare(strict_types=1);

namespace Tests\Unit\App\Commands\Concerns;

use PHPUnit\Framework\TestCase;

/**
 * Direct tests for the ResolvesTimeBudgetFlags trait.
 *
 * The double in {@see ResolvesTimeBudgetFlagsCommandDouble} exposes only
 * `getErrorStyle()` on its `getOutput()` fake, intentionally OMITTING the
 * protected `getErrorOutput()` that caused the original bug. If the trait
 * regresses to calling `getErrorOutput()`, every assertion that exercises
 * the warning path will fail loudly.
 */
class ResolvesTimeBudgetFlagsTest extends TestCase
{
    // ========================================================================
    // Happy paths
    // ========================================================================

    /** @test */
    public function it_returns_all_nulls_when_no_flags_provided(): void
    {
        $double = new ResolvesTimeBudgetFlagsCommandDouble();

        $result = $double->call();

        $this->assertSame(
            ['warnAfter' => null, 'failAfter' => null, 'disabled' => false],
            $result
        );
        $this->assertSame([], $double->errLines);
    }

    /** @test */
    public function it_parses_warn_after_alone(): void
    {
        $double = new ResolvesTimeBudgetFlagsCommandDouble();
        $double->options = ['warn-after' => '60'];

        $result = $double->call();

        $this->assertSame(60, $result['warnAfter']);
        $this->assertNull($result['failAfter']);
        $this->assertFalse($result['disabled']);
        $this->assertSame([], $double->errLines);
    }

    /** @test */
    public function it_parses_fail_after_alone(): void
    {
        $double = new ResolvesTimeBudgetFlagsCommandDouble();
        $double->options = ['fail-after' => '300'];

        $result = $double->call();

        $this->assertNull($result['warnAfter']);
        $this->assertSame(300, $result['failAfter']);
        $this->assertSame([], $double->errLines);
    }

    /** @test */
    public function it_parses_both_warn_after_and_fail_after(): void
    {
        $double = new ResolvesTimeBudgetFlagsCommandDouble();
        $double->options = ['warn-after' => '60', 'fail-after' => '300'];

        $result = $double->call();

        $this->assertSame(60, $result['warnAfter']);
        $this->assertSame(300, $result['failAfter']);
        $this->assertFalse($result['disabled']);
        $this->assertSame([], $double->errLines);
    }

    /** @test */
    public function no_time_budget_alone_disables_without_emitting_warning(): void
    {
        $double = new ResolvesTimeBudgetFlagsCommandDouble();
        $double->options = ['no-time-budget' => true];

        $result = $double->call();

        $this->assertSame(
            ['warnAfter' => null, 'failAfter' => null, 'disabled' => true],
            $result
        );
        $this->assertSame([], $double->errLines);
    }

    // ========================================================================
    // Bug regression — `--no-time-budget` mixed with --warn-after / --fail-after
    // emits a warning to stderr (must NOT throw "Call to protected method").
    // ========================================================================

    /** @test */
    public function no_time_budget_with_warn_after_clears_value_and_emits_warning(): void
    {
        $double = new ResolvesTimeBudgetFlagsCommandDouble();
        $double->options = ['no-time-budget' => true, 'warn-after' => '60'];

        $result = $double->call();

        $this->assertSame(
            ['warnAfter' => null, 'failAfter' => null, 'disabled' => true],
            $result
        );
        $this->assertSame(
            ['<comment>Warning:</comment> ignoring --warn-after due to --no-time-budget.'],
            $double->errLines
        );
    }

    /** @test */
    public function no_time_budget_with_fail_after_clears_value_and_emits_warning(): void
    {
        $double = new ResolvesTimeBudgetFlagsCommandDouble();
        $double->options = ['no-time-budget' => true, 'fail-after' => '300'];

        $result = $double->call();

        $this->assertSame(
            ['warnAfter' => null, 'failAfter' => null, 'disabled' => true],
            $result
        );
        $this->assertSame(
            ['<comment>Warning:</comment> ignoring --fail-after due to --no-time-budget.'],
            $double->errLines
        );
    }

    /** @test */
    public function no_time_budget_with_both_warn_and_fail_lists_both_in_warning(): void
    {
        $double = new ResolvesTimeBudgetFlagsCommandDouble();
        $double->options = [
            'no-time-budget' => true,
            'warn-after'     => '60',
            'fail-after'     => '300',
        ];

        $result = $double->call();

        $this->assertSame(
            ['warnAfter' => null, 'failAfter' => null, 'disabled' => true],
            $result
        );
        $this->assertSame(
            ['<comment>Warning:</comment> ignoring --warn-after/--fail-after due to --no-time-budget.'],
            $double->errLines
        );
    }

    // ========================================================================
    // parseSecondsOption — invalid integer guards (`< 1` boundary, ctype_digit)
    // ========================================================================

    /** @test */
    public function empty_string_value_is_treated_as_absent_without_warning(): void
    {
        $double = new ResolvesTimeBudgetFlagsCommandDouble();
        $double->options = ['warn-after' => ''];

        $result = $double->call();

        $this->assertNull($result['warnAfter']);
        $this->assertSame([], $double->errLines);
    }

    /** @test */
    public function null_value_is_treated_as_absent_without_warning(): void
    {
        $double = new ResolvesTimeBudgetFlagsCommandDouble();
        $double->options = ['fail-after' => null];

        $result = $double->call();

        $this->assertNull($result['failAfter']);
        $this->assertSame([], $double->errLines);
    }

    /** @test */
    public function zero_warn_after_is_rejected_with_warning(): void
    {
        $double = new ResolvesTimeBudgetFlagsCommandDouble();
        $double->options = ['warn-after' => '0'];

        $result = $double->call();

        $this->assertNull($result['warnAfter']);
        $this->assertSame(
            ["<comment>Warning:</comment> --warn-after expects a positive integer (seconds); got '0'. Ignoring."],
            $double->errLines
        );
    }

    /** @test */
    public function negative_warn_after_is_rejected_with_warning(): void
    {
        $double = new ResolvesTimeBudgetFlagsCommandDouble();
        $double->options = ['warn-after' => '-5'];

        $result = $double->call();

        $this->assertNull($result['warnAfter']);
        $this->assertSame(
            ["<comment>Warning:</comment> --warn-after expects a positive integer (seconds); got '-5'. Ignoring."],
            $double->errLines
        );
    }

    /** @test */
    public function non_numeric_fail_after_is_rejected_with_warning(): void
    {
        $double = new ResolvesTimeBudgetFlagsCommandDouble();
        $double->options = ['fail-after' => 'foo'];

        $result = $double->call();

        $this->assertNull($result['failAfter']);
        $this->assertSame(
            ["<comment>Warning:</comment> --fail-after expects a positive integer (seconds); got 'foo'. Ignoring."],
            $double->errLines
        );
    }
}
