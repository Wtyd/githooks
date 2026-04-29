<?php

declare(strict_types=1);

namespace Tests\Unit\Configuration;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\MemoryBudgetConfiguration;
use Wtyd\GitHooks\Configuration\ValidationResult;

class MemoryBudgetConfigurationTest extends TestCase
{
    /** @test */
    public function it_returns_null_when_raw_is_not_an_array(): void
    {
        $result = new ValidationResult();
        $vo = MemoryBudgetConfiguration::fromArray('invalid', $result);

        $this->assertNull($vo);
        $this->assertTrue($result->hasErrors());
        $errorText = $result->getErrors()[0];
        $this->assertStringContainsString("'memory-budget'", $errorText);
        $this->assertStringContainsString('must be an associative array', $errorText);
    }

    /** @test */
    public function it_returns_null_when_raw_array_is_empty(): void
    {
        $result = new ValidationResult();
        $vo = MemoryBudgetConfiguration::fromArray([], $result);

        $this->assertNull($vo);
        $this->assertFalse($result->hasErrors());
    }

    /** @test */
    public function it_parses_valid_warn_and_fail_above(): void
    {
        $result = new ValidationResult();
        $vo = MemoryBudgetConfiguration::fromArray(['warn-above' => 3500, 'fail-above' => 3900], $result);

        $this->assertNotNull($vo);
        $this->assertFalse($result->hasErrors());
        $this->assertSame(3500, $vo->getWarnAbove());
        $this->assertSame(3900, $vo->getFailAbove());
        $this->assertFalse($vo->isEmpty());
    }

    /** @test */
    public function it_parses_warn_above_alone(): void
    {
        $result = new ValidationResult();
        $vo = MemoryBudgetConfiguration::fromArray(['warn-above' => 2000], $result);

        $this->assertNotNull($vo);
        $this->assertSame(2000, $vo->getWarnAbove());
        $this->assertNull($vo->getFailAbove());
    }

    /** @test */
    public function it_parses_fail_above_alone(): void
    {
        $result = new ValidationResult();
        $vo = MemoryBudgetConfiguration::fromArray(['fail-above' => 4096], $result);

        $this->assertNotNull($vo);
        $this->assertNull($vo->getWarnAbove());
        $this->assertSame(4096, $vo->getFailAbove());
    }

    /** @test */
    public function it_rejects_warn_above_greater_or_equal_to_fail_above(): void
    {
        $result = new ValidationResult();
        MemoryBudgetConfiguration::fromArray(['warn-above' => 4000, 'fail-above' => 3000], $result);

        $this->assertTrue($result->hasErrors());
        $errorText = implode(' ', $result->getErrors());
        $this->assertStringContainsString("'warn-above'", $errorText);
        $this->assertStringContainsString("'fail-above'", $errorText);
        $this->assertStringContainsString('must be less than', $errorText);
        $this->assertStringContainsString('(4000)', $errorText);
        $this->assertStringContainsString('(3000)', $errorText);
    }

    /** @test */
    public function it_rejects_warn_above_equal_to_fail_above(): void
    {
        $result = new ValidationResult();
        MemoryBudgetConfiguration::fromArray(['warn-above' => 3000, 'fail-above' => 3000], $result);

        $this->assertTrue($result->hasErrors());
        $errorText = implode(' ', $result->getErrors());
        $this->assertStringContainsString('must be less than', $errorText);
        $this->assertStringContainsString('(3000)', $errorText);
    }

    /** @test */
    public function it_accepts_warn_above_one_less_than_fail_above_at_boundary(): void
    {
        // Kills GreaterThanOrEqualTo / LessThan boundary mutants on the
        // `warnAbove >= failAbove` validator.
        $result = new ValidationResult();
        $vo = MemoryBudgetConfiguration::fromArray(['warn-above' => 2999, 'fail-above' => 3000], $result);

        $this->assertNotNull($vo);
        $this->assertFalse($result->hasErrors());
        $this->assertSame(2999, $vo->getWarnAbove());
        $this->assertSame(3000, $vo->getFailAbove());
    }

    /** @test */
    public function it_rejects_zero_warn_above(): void
    {
        $result = new ValidationResult();
        MemoryBudgetConfiguration::fromArray(['warn-above' => 0], $result);

        $this->assertTrue($result->hasErrors());
        $errorText = $result->getErrors()[0];
        $this->assertStringContainsString("'warn-above'", $errorText);
        $this->assertStringContainsString('positive integer', $errorText);
        $this->assertStringContainsString('MB', $errorText);
    }

    /** @test */
    public function it_accepts_value_of_exactly_one_at_minimum_boundary(): void
    {
        // Kills LessThan / LessThanOrEqualTo mutants on `$value < 1` validator.
        $result = new ValidationResult();
        $vo = MemoryBudgetConfiguration::fromArray(['warn-above' => 1], $result);

        $this->assertNotNull($vo);
        $this->assertFalse($result->hasErrors());
        $this->assertSame(1, $vo->getWarnAbove());
    }

    /** @test */
    public function it_rejects_negative_fail_above(): void
    {
        $result = new ValidationResult();
        MemoryBudgetConfiguration::fromArray(['fail-above' => -100], $result);

        $this->assertTrue($result->hasErrors());
        $errorText = $result->getErrors()[0];
        $this->assertStringContainsString("'fail-above'", $errorText);
        $this->assertStringContainsString('positive integer', $errorText);
    }

    /** @test */
    public function it_rejects_non_integer_warn_above(): void
    {
        $result = new ValidationResult();
        MemoryBudgetConfiguration::fromArray(['warn-above' => '2000'], $result);

        $this->assertTrue($result->hasErrors());
    }

    /** @test */
    public function it_rejects_float_warn_above(): void
    {
        $result = new ValidationResult();
        MemoryBudgetConfiguration::fromArray(['warn-above' => 2000.5], $result);

        $this->assertTrue($result->hasErrors());
    }

    /** @test */
    public function it_warns_about_unknown_keys_with_suggestion(): void
    {
        $result = new ValidationResult();
        MemoryBudgetConfiguration::fromArray(['warn-above' => 2000, 'warn-aboce' => 2500], $result);

        $this->assertFalse($result->hasErrors());
        $this->assertNotEmpty($result->getWarnings());
        $warningText = implode(' ', $result->getWarnings());
        $this->assertStringContainsString('Unknown key', $warningText);
        $this->assertStringContainsString("'warn-aboce'", $warningText);
        $this->assertStringContainsString("'memory-budget'", $warningText);
        $this->assertStringContainsString('did you mean', $warningText);
        $this->assertStringContainsString("'warn-above'", $warningText);
    }

    /** @test */
    public function it_warns_about_unknown_keys_without_suggestion_when_too_distant(): void
    {
        // Kills LessThanOrEqualTo / LessThan / LogicalAnd mutants on the
        // suggestKey distance threshold (`$bestDistance <= 3`).
        $result = new ValidationResult();
        MemoryBudgetConfiguration::fromArray(
            ['warn-above' => 2000, 'totally-unrelated' => 'foo'],
            $result
        );

        $warningText = implode(' ', $result->getWarnings());
        $this->assertStringContainsString("'totally-unrelated'", $warningText);
        $this->assertStringNotContainsString('did you mean', $warningText);
    }

    /** @test */
    public function bin_packing_reference_returns_warn_above_when_present(): void
    {
        $vo = new MemoryBudgetConfiguration(3500, 3900);
        $this->assertSame(3500, $vo->getBinPackingReference());
    }

    /** @test */
    public function bin_packing_reference_falls_back_to_fail_above(): void
    {
        $vo = new MemoryBudgetConfiguration(null, 3900);
        $this->assertSame(3900, $vo->getBinPackingReference());
    }

    /** @test */
    public function bin_packing_reference_is_null_when_both_are_null(): void
    {
        $vo = new MemoryBudgetConfiguration(null, null);
        $this->assertNull($vo->getBinPackingReference());
    }

    /** @test */
    public function is_empty_returns_true_when_both_thresholds_are_null(): void
    {
        // Kills LogicalAnd / Identical mutants on `warnAbove === null && failAbove === null`.
        $vo = new MemoryBudgetConfiguration(null, null);
        $this->assertTrue($vo->isEmpty());
    }

    /** @test */
    public function is_empty_returns_false_when_only_warn_above_is_set(): void
    {
        // Kills LogicalAnd `&&` -> `||` mutant: with `||` the function would
        // return true here because failAbove is null.
        $vo = new MemoryBudgetConfiguration(2000, null);
        $this->assertFalse($vo->isEmpty());
    }

    /** @test */
    public function is_empty_returns_false_when_only_fail_above_is_set(): void
    {
        // Mirror of the previous: with `||` mutant, true would be returned
        // because warnAbove is null.
        $vo = new MemoryBudgetConfiguration(null, 3000);
        $this->assertFalse($vo->isEmpty());
    }

    /** @test */
    public function is_empty_returns_false_when_both_thresholds_are_set(): void
    {
        // Kills Identical `===` -> `!==` mutants: with `!==`, the test
        // `null !== null` returns false and isEmpty returns false; here
        // we force the original branch with neither null.
        $vo = new MemoryBudgetConfiguration(2000, 3000);
        $this->assertFalse($vo->isEmpty());
    }
}
