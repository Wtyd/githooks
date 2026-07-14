<?php

declare(strict_types=1);

namespace Tests\Unit\Configuration;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Configuration\MemoryThreshold;
use Wtyd\GitHooks\Configuration\ValidationResult;

class MemoryThresholdTest extends UnitTestCase
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
        $this->assertStringContainsString("Job 'phpstan'", $errorText);
        $this->assertStringContainsString("'warn-above'", $errorText);
        $this->assertStringContainsString("'fail-above'", $errorText);
        $this->assertStringContainsString('must be less than', $errorText);
        $this->assertStringContainsString('(2000)', $errorText);
        $this->assertStringContainsString('(1500)', $errorText);
        $this->assertStringContainsString("in 'memory'", $errorText);
    }

    /** @test */
    public function from_array_rejects_warn_above_equal_to_fail_above_at_boundary(): void
    {
        $result = new ValidationResult();
        MemoryThreshold::fromArray(
            ['warn-above' => 1500, 'fail-above' => 1500],
            $result,
            'phpstan'
        );

        $this->assertTrue($result->hasErrors());
        $errorText = implode(' ', $result->getErrors());
        $this->assertStringContainsString('must be less than', $errorText);
        $this->assertStringContainsString('(1500)', $errorText);
    }

    /** @test */
    public function from_array_accepts_warn_above_one_less_than_fail_above_boundary(): void
    {
        $result = new ValidationResult();
        $threshold = MemoryThreshold::fromArray(
            ['warn-above' => 1499, 'fail-above' => 1500],
            $result,
            'phpstan'
        );

        $this->assertNotNull($threshold);
        $this->assertFalse($result->hasErrors());
        $this->assertSame(1499, $threshold->getWarnAbove());
        $this->assertSame(1500, $threshold->getFailAbove());
    }

    /** @test */
    public function from_array_rejects_zero_warn_above(): void
    {
        $result = new ValidationResult();
        MemoryThreshold::fromArray(['warn-above' => 0], $result, 'phpstan');

        $this->assertTrue($result->hasErrors());
        $errorText = implode(' ', $result->getErrors());
        $this->assertStringContainsString("Job 'phpstan'", $errorText);
        $this->assertStringContainsString("'warn-above'", $errorText);
        $this->assertStringContainsString('positive integer', $errorText);
        $this->assertStringContainsString('MB', $errorText);
        $this->assertStringContainsString("in 'memory'", $errorText);
    }

    /** @test */
    public function from_array_accepts_value_of_exactly_one_at_minimum_boundary(): void
    {
        // Kills LessThan / LessThanOrEqualTo mutants on `$value < 1` validator.
        $result = new ValidationResult();
        $threshold = MemoryThreshold::fromArray(['warn-above' => 1], $result, 'phpstan');

        $this->assertNotNull($threshold);
        $this->assertFalse($result->hasErrors());
        $this->assertSame(1, $threshold->getWarnAbove());
    }

    /** @test */
    public function from_array_rejects_non_integer_value(): void
    {
        $result = new ValidationResult();
        MemoryThreshold::fromArray(['warn-above' => '2000'], $result, 'phpstan');

        $this->assertTrue($result->hasErrors());
        $errorText = implode(' ', $result->getErrors());
        $this->assertStringContainsString('positive integer', $errorText);
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
        $this->assertStringContainsString("Job 'phpstan'", $warningText);
        $this->assertStringContainsString('unknown key', $warningText);
        $this->assertStringContainsString("'warn-aboce'", $warningText);
        $this->assertStringContainsString("in 'memory'", $warningText);
        $this->assertStringContainsString('did you mean', $warningText);
        $this->assertStringContainsString("'warn-above'", $warningText);
    }

    /** @test */
    public function from_array_warns_about_unknown_keys_without_suggestion_when_too_distant(): void
    {
        // Kills LessThanOrEqualTo / LessThan / LogicalAnd mutants on the
        // suggestKey distance threshold (`$bestDistance <= 3`).
        $result = new ValidationResult();
        MemoryThreshold::fromArray(
            ['warn-above' => 1500, 'totally-unrelated-key' => 2000],
            $result,
            'phpstan'
        );

        $warningText = implode(' ', $result->getWarnings());
        $this->assertStringContainsString("'totally-unrelated-key'", $warningText);
        $this->assertStringNotContainsString('did you mean', $warningText);
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

    /**
     * @test
     * @dataProvider fromOverrideScenarios
     *
     * A CLI memory override (`githooks job --memory-warn-above / --memory-fail-above`)
     * builds an EXTENDED-form threshold: it only thresholds the sampled peak and
     * must never participate in the 2D scheduler reservation, so `isShortForm()`
     * stays false and `getReserve()` stays null regardless of which flag(s) were
     * given. Pins MemoryThreshold:98 (`shortForm=false` in fromOverride): the
     * short-form flag on an override object is a documented invariant — nothing
     * reads its reserve today, and this test guarantees a future wiring can't
     * silently turn a CLI threshold into a slot reservation.
     *
     * @param int|null $warnAbove
     * @param int|null $failAbove
     */
    public function from_override_builds_extended_form_that_never_reserves(
        ?int $warnAbove,
        ?int $failAbove
    ): void {
        $threshold = MemoryThreshold::fromOverride($warnAbove, $failAbove);

        $this->assertNotNull($threshold);
        $this->assertSame($warnAbove, $threshold->getWarnAbove());
        $this->assertSame($failAbove, $threshold->getFailAbove());
        $this->assertFalse($threshold->isShortForm());
        $this->assertNull($threshold->getReserve());
    }

    /**
     * @return array<string, array{0: int|null, 1: int|null}>
     */
    public function fromOverrideScenarios(): array
    {
        return [
            'warn-above only'      => [1500, null],
            'fail-above only'      => [null, 2500],
            'both warn and fail'   => [1500, 2000],
        ];
    }

    /** @test */
    public function from_override_returns_null_when_no_flag_is_given(): void
    {
        $this->assertNull(MemoryThreshold::fromOverride(null, null));
    }

    /**
     * @test
     * Kills MemoryThreshold:118 LessThan `<` -> `<=` on the suggestKey()
     * helper. The needle 'vain-above' is at exact distance 2 from BOTH
     * known keys, so iteration order decides the winner: original picks
     * 'warn-above' (first), mutant picks 'fail-above' (last).
     */
    public function unknown_key_at_distance_tie_suggests_first_match(): void
    {
        $result = new ValidationResult();
        MemoryThreshold::fromArray(
            ['warn-above' => 100, 'vain-above' => 200],
            $result,
            'phpunit'
        );

        $warnings = $result->getWarnings();
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString("did you mean 'warn-above'", $warnings[0]);
        $this->assertStringNotContainsString("'fail-above'", $warnings[0]);
    }

    /**
     * @test
     * Kills MemoryThreshold:123 LessThanOrEqualTo `<=3` -> `<3` on
     * suggestKey()'s final threshold. 'warningabove' is at exact distance
     * 3 from 'warn-above'. With `<=3` the suggestion fires; with `<3`
     * it does not.
     */
    public function unknown_key_at_distance_3_boundary_still_suggests(): void
    {
        $result = new ValidationResult();
        MemoryThreshold::fromArray(
            ['warn-above' => 100, 'warningabove' => 200],
            $result,
            'phpunit'
        );

        $warnings = $result->getWarnings();
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString("did you mean 'warn-above'", $warnings[0]);
    }
}
