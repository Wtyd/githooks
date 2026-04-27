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
        $this->assertStringContainsString('memory-budget', $result->getErrors()[0]);
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
        $this->assertStringContainsString('warn-above', $errorText);
        $this->assertStringContainsString('fail-above', $errorText);
    }

    /** @test */
    public function it_rejects_warn_above_equal_to_fail_above(): void
    {
        $result = new ValidationResult();
        MemoryBudgetConfiguration::fromArray(['warn-above' => 3000, 'fail-above' => 3000], $result);

        $this->assertTrue($result->hasErrors());
    }

    /** @test */
    public function it_rejects_zero_warn_above(): void
    {
        $result = new ValidationResult();
        MemoryBudgetConfiguration::fromArray(['warn-above' => 0], $result);

        $this->assertTrue($result->hasErrors());
        $this->assertStringContainsString("'warn-above'", $result->getErrors()[0]);
        $this->assertStringContainsString('MB', $result->getErrors()[0]);
    }

    /** @test */
    public function it_rejects_negative_fail_above(): void
    {
        $result = new ValidationResult();
        MemoryBudgetConfiguration::fromArray(['fail-above' => -100], $result);

        $this->assertTrue($result->hasErrors());
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
        $this->assertStringContainsString('warn-aboce', $warningText);
        $this->assertStringContainsString('warn-above', $warningText);
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
}
