<?php

declare(strict_types=1);

namespace Tests\Unit\Configuration;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\MemoryThreshold;
use Wtyd\GitHooks\Configuration\ValidationResult;

class MemoryThresholdTest extends TestCase
{
    /** @test */
    public function from_int_creates_short_form_threshold_with_warn_above_only(): void
    {
        $threshold = MemoryThreshold::fromInt(2000);

        $this->assertSame(2000, $threshold->getWarnAbove());
        $this->assertNull($threshold->getFailAbove());
        $this->assertTrue($threshold->isShortForm());
        $this->assertSame(2000, $threshold->getReserve());
    }

    /** @test */
    public function from_array_creates_extended_form_with_warn_above(): void
    {
        $result = new ValidationResult();
        $threshold = MemoryThreshold::fromArray(['warn-above' => 1500], $result, 'phpstan');

        $this->assertNotNull($threshold);
        $this->assertSame(1500, $threshold->getWarnAbove());
        $this->assertNull($threshold->getFailAbove());
        $this->assertFalse($threshold->isShortForm());
        $this->assertNull($threshold->getReserve());
    }

    /** @test */
    public function from_array_creates_extended_form_with_fail_above(): void
    {
        $result = new ValidationResult();
        $threshold = MemoryThreshold::fromArray(['fail-above' => 2500], $result, 'phpstan');

        $this->assertNotNull($threshold);
        $this->assertNull($threshold->getWarnAbove());
        $this->assertSame(2500, $threshold->getFailAbove());
        $this->assertFalse($threshold->isShortForm());
        $this->assertNull($threshold->getReserve());
    }

    /** @test */
    public function from_array_with_both_thresholds(): void
    {
        $result = new ValidationResult();
        $threshold = MemoryThreshold::fromArray(
            ['warn-above' => 1500, 'fail-above' => 2000],
            $result,
            'phpunit'
        );

        $this->assertNotNull($threshold);
        $this->assertSame(1500, $threshold->getWarnAbove());
        $this->assertSame(2000, $threshold->getFailAbove());
        $this->assertFalse($result->hasErrors());
    }

    /** @test */
    public function from_array_returns_null_when_empty(): void
    {
        $result = new ValidationResult();
        $threshold = MemoryThreshold::fromArray([], $result, 'phpstan');

        $this->assertNull($threshold);
        $this->assertFalse($result->hasErrors());
    }

    /** @test */
    public function from_array_emits_error_when_warn_above_is_greater_or_equal_to_fail_above(): void
    {
        $result = new ValidationResult();
        MemoryThreshold::fromArray(
            ['warn-above' => 2000, 'fail-above' => 1500],
            $result,
            'phpstan'
        );

        $this->assertTrue($result->hasErrors());
        $errorText = implode(' ', $result->getErrors());
        $this->assertStringContainsString('phpstan', $errorText);
        $this->assertStringContainsString('warn-above', $errorText);
        $this->assertStringContainsString('fail-above', $errorText);
    }

    /** @test */
    public function from_array_rejects_zero_warn_above(): void
    {
        $result = new ValidationResult();
        MemoryThreshold::fromArray(['warn-above' => 0], $result, 'phpstan');

        $this->assertTrue($result->hasErrors());
        $errorText = implode(' ', $result->getErrors());
        $this->assertStringContainsString('MB', $errorText);
    }

    /** @test */
    public function from_array_rejects_non_integer_value(): void
    {
        $result = new ValidationResult();
        MemoryThreshold::fromArray(['warn-above' => '2000'], $result, 'phpstan');

        $this->assertTrue($result->hasErrors());
    }

    /** @test */
    public function from_array_warns_about_unknown_keys_with_suggestion(): void
    {
        $result = new ValidationResult();
        MemoryThreshold::fromArray(
            ['warn-above' => 1500, 'warn-aboce' => 2000],
            $result,
            'phpstan'
        );

        $this->assertFalse($result->hasErrors());
        $warningText = implode(' ', $result->getWarnings());
        $this->assertStringContainsString('phpstan', $warningText);
        $this->assertStringContainsString('warn-aboce', $warningText);
        $this->assertStringContainsString('warn-above', $warningText);
    }

    /** @test */
    public function reserve_is_null_for_extended_form_with_both_thresholds(): void
    {
        $result = new ValidationResult();
        $threshold = MemoryThreshold::fromArray(
            ['warn-above' => 1500, 'fail-above' => 2000],
            $result,
            'phpunit'
        );

        $this->assertNull($threshold->getReserve());
    }
}
