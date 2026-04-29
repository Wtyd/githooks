<?php

declare(strict_types=1);

namespace Tests\Unit\Configuration;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\TimeBudgetConfiguration;
use Wtyd\GitHooks\Configuration\ValidationResult;

class TimeBudgetConfigurationTest extends TestCase
{
    /** @test */
    public function it_returns_null_when_raw_is_not_an_array(): void
    {
        $result = new ValidationResult();
        $vo = TimeBudgetConfiguration::fromArray('invalid', $result);

        $this->assertNull($vo);
        $this->assertTrue($result->hasErrors());
        $errorText = $result->getErrors()[0];
        $this->assertStringContainsString("'time-budget'", $errorText);
        $this->assertStringContainsString('must be an associative array', $errorText);
    }

    /** @test */
    public function it_returns_null_when_raw_array_is_empty(): void
    {
        $result = new ValidationResult();
        $vo = TimeBudgetConfiguration::fromArray([], $result);

        $this->assertNull($vo);
        $this->assertFalse($result->hasErrors());
    }

    /** @test */
    public function it_parses_valid_warn_and_fail_after(): void
    {
        $result = new ValidationResult();
        $vo = TimeBudgetConfiguration::fromArray(['warn-after' => 120, 'fail-after' => 300], $result);

        $this->assertNotNull($vo);
        $this->assertFalse($result->hasErrors());
        $this->assertSame(120, $vo->getWarnAfter());
        $this->assertSame(300, $vo->getFailAfter());
        $this->assertFalse($vo->isEmpty());
    }

    /** @test */
    public function it_parses_warn_after_alone(): void
    {
        $result = new ValidationResult();
        $vo = TimeBudgetConfiguration::fromArray(['warn-after' => 60], $result);

        $this->assertNotNull($vo);
        $this->assertSame(60, $vo->getWarnAfter());
        $this->assertNull($vo->getFailAfter());
    }

    /** @test */
    public function it_parses_fail_after_alone(): void
    {
        $result = new ValidationResult();
        $vo = TimeBudgetConfiguration::fromArray(['fail-after' => 300], $result);

        $this->assertNotNull($vo);
        $this->assertNull($vo->getWarnAfter());
        $this->assertSame(300, $vo->getFailAfter());
    }

    /** @test */
    public function it_rejects_warn_after_greater_or_equal_to_fail_after(): void
    {
        $result = new ValidationResult();
        TimeBudgetConfiguration::fromArray(['warn-after' => 300, 'fail-after' => 120], $result);

        $this->assertTrue($result->hasErrors());
        $errorText = implode(' ', $result->getErrors());
        $this->assertStringContainsString("'warn-after'", $errorText);
        $this->assertStringContainsString("'fail-after'", $errorText);
        $this->assertStringContainsString('must be less than', $errorText);
        $this->assertStringContainsString('(300)', $errorText);
        $this->assertStringContainsString('(120)', $errorText);
    }

    /** @test */
    public function it_rejects_warn_after_equal_to_fail_after(): void
    {
        $result = new ValidationResult();
        TimeBudgetConfiguration::fromArray(['warn-after' => 120, 'fail-after' => 120], $result);

        $this->assertTrue($result->hasErrors());
        $errorText = implode(' ', $result->getErrors());
        $this->assertStringContainsString('must be less than', $errorText);
        $this->assertStringContainsString('(120)', $errorText);
    }

    /** @test */
    public function it_accepts_warn_after_one_less_than_fail_after_at_boundary(): void
    {
        // Kills GreaterThanOrEqualTo / LessThan boundary mutants on the
        // `warnAfter >= failAfter` validator.
        $result = new ValidationResult();
        $vo = TimeBudgetConfiguration::fromArray(['warn-after' => 119, 'fail-after' => 120], $result);

        $this->assertNotNull($vo);
        $this->assertFalse($result->hasErrors());
        $this->assertSame(119, $vo->getWarnAfter());
        $this->assertSame(120, $vo->getFailAfter());
    }

    /** @test */
    public function it_rejects_zero_warn_after(): void
    {
        $result = new ValidationResult();
        TimeBudgetConfiguration::fromArray(['warn-after' => 0], $result);

        $this->assertTrue($result->hasErrors());
        $errorText = $result->getErrors()[0];
        $this->assertStringContainsString("'warn-after'", $errorText);
        $this->assertStringContainsString('positive integer', $errorText);
    }

    /** @test */
    public function it_accepts_value_of_exactly_one_at_minimum_boundary(): void
    {
        // Kills LessThan / LessThanOrEqualTo mutants on `$value < 1` validator.
        $result = new ValidationResult();
        $vo = TimeBudgetConfiguration::fromArray(['warn-after' => 1], $result);

        $this->assertNotNull($vo);
        $this->assertFalse($result->hasErrors());
        $this->assertSame(1, $vo->getWarnAfter());
    }

    /** @test */
    public function it_rejects_negative_fail_after(): void
    {
        $result = new ValidationResult();
        TimeBudgetConfiguration::fromArray(['fail-after' => -5], $result);

        $this->assertTrue($result->hasErrors());
        $errorText = $result->getErrors()[0];
        $this->assertStringContainsString("'fail-after'", $errorText);
        $this->assertStringContainsString('positive integer', $errorText);
    }

    /** @test */
    public function it_rejects_non_integer_warn_after(): void
    {
        $result = new ValidationResult();
        TimeBudgetConfiguration::fromArray(['warn-after' => '60'], $result);

        $this->assertTrue($result->hasErrors());
    }

    /** @test */
    public function it_rejects_float_warn_after(): void
    {
        $result = new ValidationResult();
        TimeBudgetConfiguration::fromArray(['warn-after' => 1.5], $result);

        $this->assertTrue($result->hasErrors());
    }

    /** @test */
    public function it_warns_about_unknown_keys_with_suggestion(): void
    {
        $result = new ValidationResult();
        TimeBudgetConfiguration::fromArray(['warn-after' => 60, 'warn-affter' => 120], $result);

        $this->assertFalse($result->hasErrors());
        $this->assertNotEmpty($result->getWarnings());
        $warningText = implode(' ', $result->getWarnings());
        $this->assertStringContainsString('Unknown key', $warningText);
        $this->assertStringContainsString("'warn-affter'", $warningText);
        $this->assertStringContainsString("'time-budget'", $warningText);
        $this->assertStringContainsString('did you mean', $warningText);
        $this->assertStringContainsString("'warn-after'", $warningText);
    }

    /** @test */
    public function it_warns_about_unknown_keys_without_suggestion(): void
    {
        $result = new ValidationResult();
        TimeBudgetConfiguration::fromArray(['warn-after' => 60, 'unrelated-key' => 'foo'], $result);

        $warningText = implode(' ', $result->getWarnings());
        $this->assertStringContainsString("'unrelated-key'", $warningText);
        $this->assertStringContainsString("'time-budget'", $warningText);
        $this->assertStringNotContainsString('did you mean', $warningText);
    }
}
