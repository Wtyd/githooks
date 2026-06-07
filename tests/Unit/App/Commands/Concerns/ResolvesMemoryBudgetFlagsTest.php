<?php

declare(strict_types=1);

namespace Tests\Unit\App\Commands\Concerns;

use Tests\Utils\TestCase\UnitTestCase;

/**
 * Direct tests for the ResolvesMemoryBudgetFlags trait.
 */
class ResolvesMemoryBudgetFlagsTest extends UnitTestCase
{
    /** @test */
    public function it_returns_all_nulls_when_no_flags_provided(): void
    {
        $double = new ResolvesMemoryBudgetFlagsCommandDouble();

        $result = $double->call();

        $this->assertSame(
            ['warnAbove' => null, 'failAbove' => null, 'disabled' => false],
            $result
        );
        $this->assertSame([], $double->errLines);
    }

    /** @test */
    public function it_parses_warn_above_alone(): void
    {
        $double = new ResolvesMemoryBudgetFlagsCommandDouble();
        $double->options = ['memory-warn-above' => '2000'];

        $result = $double->call();

        $this->assertSame(2000, $result['warnAbove']);
        $this->assertNull($result['failAbove']);
        $this->assertFalse($result['disabled']);
        $this->assertSame([], $double->errLines);
    }

    /** @test */
    public function it_parses_fail_above_alone(): void
    {
        $double = new ResolvesMemoryBudgetFlagsCommandDouble();
        $double->options = ['memory-fail-above' => '3500'];

        $result = $double->call();

        $this->assertNull($result['warnAbove']);
        $this->assertSame(3500, $result['failAbove']);
        $this->assertSame([], $double->errLines);
    }

    /** @test */
    public function it_parses_both_warn_and_fail_above(): void
    {
        $double = new ResolvesMemoryBudgetFlagsCommandDouble();
        $double->options = ['memory-warn-above' => '2000', 'memory-fail-above' => '3500'];

        $result = $double->call();

        $this->assertSame(2000, $result['warnAbove']);
        $this->assertSame(3500, $result['failAbove']);
        $this->assertFalse($result['disabled']);
    }

    /** @test */
    public function no_memory_budget_alone_disables_without_emitting_warning(): void
    {
        $double = new ResolvesMemoryBudgetFlagsCommandDouble();
        $double->options = ['no-memory-budget' => true];

        $result = $double->call();

        $this->assertSame(
            ['warnAbove' => null, 'failAbove' => null, 'disabled' => true],
            $result
        );
        $this->assertSame([], $double->errLines);
    }

    /** @test */
    public function no_memory_budget_with_warn_above_clears_value_and_emits_warning(): void
    {
        $double = new ResolvesMemoryBudgetFlagsCommandDouble();
        $double->options = ['no-memory-budget' => true, 'memory-warn-above' => '2000'];

        $result = $double->call();

        $this->assertSame(
            ['warnAbove' => null, 'failAbove' => null, 'disabled' => true],
            $result
        );
        $this->assertSame(
            ['<comment>Warning:</comment> ignoring --memory-warn-above due to --no-memory-budget.'],
            $double->errLines
        );
    }

    /** @test */
    public function no_memory_budget_with_both_warn_and_fail_lists_both_in_warning(): void
    {
        $double = new ResolvesMemoryBudgetFlagsCommandDouble();
        $double->options = [
            'no-memory-budget'  => true,
            'memory-warn-above' => '2000',
            'memory-fail-above' => '3500',
        ];

        $result = $double->call();

        $this->assertSame(
            ['warnAbove' => null, 'failAbove' => null, 'disabled' => true],
            $result
        );
        $this->assertSame(
            ['<comment>Warning:</comment> ignoring --memory-warn-above/--memory-fail-above due to --no-memory-budget.'],
            $double->errLines
        );
    }

    /** @test */
    public function empty_string_value_is_treated_as_absent_without_warning(): void
    {
        $double = new ResolvesMemoryBudgetFlagsCommandDouble();
        $double->options = ['memory-warn-above' => ''];

        $result = $double->call();

        $this->assertNull($result['warnAbove']);
        $this->assertSame([], $double->errLines);
    }

    /** @test */
    public function zero_warn_above_is_rejected_with_warning(): void
    {
        $double = new ResolvesMemoryBudgetFlagsCommandDouble();
        $double->options = ['memory-warn-above' => '0'];

        $result = $double->call();

        $this->assertNull($result['warnAbove']);
        $this->assertSame(
            ["<comment>Warning:</comment> --memory-warn-above expects a positive integer (MB); got '0'. Ignoring."],
            $double->errLines
        );
    }

    /** @test */
    public function negative_fail_above_is_rejected_with_warning(): void
    {
        $double = new ResolvesMemoryBudgetFlagsCommandDouble();
        $double->options = ['memory-fail-above' => '-100'];

        $result = $double->call();

        $this->assertNull($result['failAbove']);
        $this->assertSame(
            ["<comment>Warning:</comment> --memory-fail-above expects a positive integer (MB); got '-100'. Ignoring."],
            $double->errLines
        );
    }

    /** @test */
    public function non_numeric_warn_above_is_rejected_with_warning(): void
    {
        $double = new ResolvesMemoryBudgetFlagsCommandDouble();
        $double->options = ['memory-warn-above' => 'lots'];

        $result = $double->call();

        $this->assertNull($result['warnAbove']);
        $this->assertSame(
            ["<comment>Warning:</comment> --memory-warn-above expects a positive integer (MB); got 'lots'. Ignoring."],
            $double->errLines
        );
    }
}
