<?php

declare(strict_types=1);

namespace Tests\Unit\Configuration;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Configuration\ValidationResult;

class OptionsConfigurationTest extends TestCase
{
    /** @test */
    public function it_uses_defaults_when_empty()
    {
        $options = new OptionsConfiguration();

        $this->assertFalse($options->isFailFast());
        $this->assertEquals(1, $options->getProcesses());
    }

    /** @test */
    public function it_parses_valid_options()
    {
        $result = new ValidationResult();
        $options = OptionsConfiguration::fromArray(['fail-fast' => true, 'processes' => 4], $result);

        $this->assertFalse($result->hasErrors());
        $this->assertTrue($options->isFailFast());
        $this->assertEquals(4, $options->getProcesses());
    }

    /** @test */
    public function it_reports_error_for_non_boolean_fail_fast()
    {
        $result = new ValidationResult();
        OptionsConfiguration::fromArray(['fail-fast' => 'yes'], $result);

        $this->assertTrue($result->hasErrors());
        $this->assertStringContainsString('fail-fast', $result->getErrors()[0]);
    }

    /** @test */
    public function it_reports_error_for_non_integer_processes()
    {
        $result = new ValidationResult();
        OptionsConfiguration::fromArray(['processes' => 'many'], $result);

        $this->assertTrue($result->hasErrors());
        $this->assertStringContainsString('processes', $result->getErrors()[0]);
    }

    /** @test */
    public function it_reports_error_for_negative_processes()
    {
        $result = new ValidationResult();
        OptionsConfiguration::fromArray(['processes' => -1], $result);

        $this->assertTrue($result->hasErrors());
    }

    /** @test */
    public function it_warns_about_unknown_keys()
    {
        $result = new ValidationResult();
        OptionsConfiguration::fromArray(['fail-fast' => false, 'unknown' => 'value'], $result);

        $this->assertFalse($result->hasErrors());
        $this->assertCount(1, $result->getWarnings());
        $this->assertStringContainsString('unknown', $result->getWarnings()[0]);
    }

    /** @test */
    public function defaults_returns_default_values()
    {
        $options = OptionsConfiguration::defaults();

        $this->assertFalse($options->isFailFast());
        $this->assertEquals(1, $options->getProcesses());
    }

    /** @test */
    public function with_overrides_applies_fail_fast()
    {
        $options = new OptionsConfiguration(false, 2);
        $overridden = $options->withOverrides(true, null);

        $this->assertTrue($overridden->isFailFast());
        $this->assertEquals(2, $overridden->getProcesses());
    }

    /** @test */
    public function with_overrides_applies_processes()
    {
        $options = new OptionsConfiguration(true, 1);
        $overridden = $options->withOverrides(null, 8);

        $this->assertTrue($overridden->isFailFast());
        $this->assertEquals(8, $overridden->getProcesses());
    }

    /** @test */
    public function with_overrides_applies_both()
    {
        $options = new OptionsConfiguration(false, 1);
        $overridden = $options->withOverrides(true, 4);

        $this->assertTrue($overridden->isFailFast());
        $this->assertEquals(4, $overridden->getProcesses());
    }

    /** @test */
    public function with_overrides_keeps_original_when_nulls()
    {
        $options = new OptionsConfiguration(true, 8);
        $overridden = $options->withOverrides(null, null);

        $this->assertTrue($overridden->isFailFast());
        $this->assertEquals(8, $overridden->getProcesses());
    }

    /** @test */
    public function with_overrides_does_not_mutate_original()
    {
        $original = new OptionsConfiguration(false, 1);
        $original->withOverrides(true, 4);

        $this->assertFalse($original->isFailFast());
        $this->assertEquals(1, $original->getProcesses());
    }

    // ========================================================================
    // Execution mode options (TDD — will fail until implementation exists)
    // ========================================================================

    /** @test */
    public function it_parses_main_branch_option()
    {
        $result = new ValidationResult();
        $options = OptionsConfiguration::fromArray([
            'main-branch' => 'develop',
        ], $result);

        $this->assertFalse($result->hasErrors());
        $this->assertEquals('develop', $options->getMainBranch());
    }

    /** @test */
    public function it_defaults_main_branch_to_null()
    {
        $options = new OptionsConfiguration();

        $this->assertNull($options->getMainBranch());
    }

    /** @test */
    public function it_reports_error_for_non_string_main_branch()
    {
        $result = new ValidationResult();
        OptionsConfiguration::fromArray(['main-branch' => 123], $result);

        $this->assertTrue($result->hasErrors());
        $this->assertStringContainsString('main-branch', $result->getErrors()[0]);
    }

    /** @test */
    public function it_parses_fast_branch_fallback_as_full()
    {
        $result = new ValidationResult();
        $options = OptionsConfiguration::fromArray([
            'fast-branch-fallback' => 'full',
        ], $result);

        $this->assertFalse($result->hasErrors());
        $this->assertEquals('full', $options->getFastBranchFallback());
    }

    /** @test */
    public function it_parses_fast_branch_fallback_as_fast()
    {
        $result = new ValidationResult();
        $options = OptionsConfiguration::fromArray([
            'fast-branch-fallback' => 'fast',
        ], $result);

        $this->assertFalse($result->hasErrors());
        $this->assertEquals('fast', $options->getFastBranchFallback());
    }

    /** @test */
    public function it_defaults_fast_branch_fallback_to_full()
    {
        $options = new OptionsConfiguration();

        $this->assertEquals('full', $options->getFastBranchFallback());
    }

    /** @test */
    public function it_reports_error_for_invalid_fast_branch_fallback()
    {
        $result = new ValidationResult();
        OptionsConfiguration::fromArray(['fast-branch-fallback' => 'fast-branch'], $result);

        $this->assertTrue($result->hasErrors());
        $this->assertStringContainsString('fast-branch-fallback', $result->getErrors()[0]);
    }

    /** @test */
    public function main_branch_and_fast_branch_fallback_are_not_unknown_keys()
    {
        $result = new ValidationResult();
        OptionsConfiguration::fromArray([
            'main-branch' => 'master',
            'fast-branch-fallback' => 'full',
        ], $result);

        $this->assertFalse($result->hasErrors());
        $this->assertEmpty($result->getWarnings());
    }

    // ========================================================================
    // executable-prefix option
    // ========================================================================

    /** @test */
    public function it_parses_executable_prefix_option()
    {
        $result = new ValidationResult();
        $options = OptionsConfiguration::fromArray([
            'executable-prefix' => 'docker exec -i app',
        ], $result);

        $this->assertFalse($result->hasErrors());
        $this->assertEquals('docker exec -i app', $options->getExecutablePrefix());
    }

    /** @test */
    public function it_defaults_executable_prefix_to_empty_string()
    {
        $options = new OptionsConfiguration();

        $this->assertEquals('', $options->getExecutablePrefix());
    }

    /** @test */
    public function it_reports_error_for_non_string_executable_prefix()
    {
        $result = new ValidationResult();
        OptionsConfiguration::fromArray(['executable-prefix' => 123], $result);

        $this->assertTrue($result->hasErrors());
        $this->assertStringContainsString('executable-prefix', $result->getErrors()[0]);
    }

    /** @test */
    public function executable_prefix_is_not_an_unknown_key()
    {
        $result = new ValidationResult();
        OptionsConfiguration::fromArray(['executable-prefix' => 'sail exec app'], $result);

        $this->assertFalse($result->hasErrors());
        $this->assertEmpty($result->getWarnings());
    }

    /** @test */
    public function with_overrides_preserves_executable_prefix()
    {
        $options = new OptionsConfiguration(false, 1, null, 'full', 'docker exec app');
        $overridden = $options->withOverrides(true, 4);

        $this->assertEquals('docker exec app', $overridden->getExecutablePrefix());
        $this->assertTrue($overridden->isFailFast());
        $this->assertEquals(4, $overridden->getProcesses());
    }

    // ========================================================================
    // reports option (multi-report v3.3 — declarative format → path map)
    // ========================================================================

    /** @test */
    public function it_defaults_reports_to_empty_array()
    {
        $options = new OptionsConfiguration();

        $this->assertSame([], $options->getReports());
    }

    /** @test */
    public function it_parses_reports_with_all_four_formats()
    {
        $result = new ValidationResult();
        $options = OptionsConfiguration::fromArray([
            'reports' => [
                'json'        => 'reports/qa.json',
                'junit'       => 'reports/junit.xml',
                'sarif'       => 'reports/qa.sarif',
                'codeclimate' => 'reports/cc.json',
            ],
        ], $result);

        $this->assertFalse($result->hasErrors());
        $this->assertSame([
            'json'        => 'reports/qa.json',
            'junit'       => 'reports/junit.xml',
            'sarif'       => 'reports/qa.sarif',
            'codeclimate' => 'reports/cc.json',
        ], $options->getReports());
    }

    /** @test */
    public function it_parses_a_subset_of_report_formats()
    {
        $result = new ValidationResult();
        $options = OptionsConfiguration::fromArray([
            'reports' => [
                'sarif' => 'reports/q.sarif',
                'junit' => 'reports/j.xml',
            ],
        ], $result);

        $this->assertFalse($result->hasErrors());
        $this->assertSame(
            ['sarif' => 'reports/q.sarif', 'junit' => 'reports/j.xml'],
            $options->getReports()
        );
    }

    /** @test */
    public function it_reports_error_for_invalid_report_format_key()
    {
        $result = new ValidationResult();
        OptionsConfiguration::fromArray([
            'reports' => ['xml' => 'foo.xml'],
        ], $result);

        $this->assertTrue($result->hasErrors());
        $this->assertStringContainsString("invalid format 'xml'", $result->getErrors()[0]);
    }

    /** @test */
    public function it_reports_error_for_text_format_in_reports()
    {
        $result = new ValidationResult();
        OptionsConfiguration::fromArray([
            'reports' => ['text' => 'foo.txt'],
        ], $result);

        $this->assertTrue($result->hasErrors());
        $this->assertStringContainsString("invalid format 'text'", $result->getErrors()[0]);
    }

    /** @test */
    public function it_reports_error_for_non_string_report_path()
    {
        $result = new ValidationResult();
        OptionsConfiguration::fromArray([
            'reports' => ['sarif' => 123],
        ], $result);

        $this->assertTrue($result->hasErrors());
        $this->assertStringContainsString("'reports.sarif' must be a non-empty string path", $result->getErrors()[0]);
    }

    /** @test */
    public function it_reports_error_for_empty_report_path()
    {
        $result = new ValidationResult();
        OptionsConfiguration::fromArray([
            'reports' => ['sarif' => ''],
        ], $result);

        $this->assertTrue($result->hasErrors());
        $this->assertStringContainsString("'reports.sarif' must be a non-empty string path", $result->getErrors()[0]);
    }

    /** @test */
    public function it_reports_error_when_reports_is_not_an_array()
    {
        $result = new ValidationResult();
        OptionsConfiguration::fromArray(['reports' => 'foo.sarif'], $result);

        $this->assertTrue($result->hasErrors());
        $this->assertStringContainsString("'reports' must be a map", $result->getErrors()[0]);
    }

    /** @test */
    public function reports_is_not_an_unknown_key()
    {
        $result = new ValidationResult();
        OptionsConfiguration::fromArray([
            'reports' => ['sarif' => 'q.sarif'],
        ], $result);

        $this->assertFalse($result->hasErrors());
        $this->assertEmpty($result->getWarnings());
    }

    /** @test */
    public function with_overrides_preserves_reports()
    {
        $options = new OptionsConfiguration(false, 1, null, 'full', '', ['sarif' => 'q.sarif']);
        $overridden = $options->withOverrides(true, 4);

        $this->assertSame(['sarif' => 'q.sarif'], $overridden->getReports());
    }

    /** @test */
    public function valid_report_formats_constant_exposes_supported_set()
    {
        $this->assertSame(
            ['json', 'junit', 'sarif', 'codeclimate'],
            OptionsConfiguration::VALID_REPORT_FORMATS
        );
    }

    // ========================================================================
    // time-budget option (v3.3 item 4 — flow time-budget)
    // ========================================================================

    /** @test */
    public function it_defaults_time_budget_to_null(): void
    {
        $options = new OptionsConfiguration();

        $this->assertNull($options->getTimeBudget());
    }

    /** @test */
    public function it_parses_time_budget_with_warn_and_fail_after(): void
    {
        $result = new \Wtyd\GitHooks\Configuration\ValidationResult();
        $options = \Wtyd\GitHooks\Configuration\OptionsConfiguration::fromArray([
            'time-budget' => ['warn-after' => 120, 'fail-after' => 300],
        ], $result);

        $this->assertFalse($result->hasErrors());
        $this->assertNotNull($options->getTimeBudget());
        $this->assertSame(120, $options->getTimeBudget()->getWarnAfter());
        $this->assertSame(300, $options->getTimeBudget()->getFailAfter());
        $this->assertTrue($options->hasKey('time-budget'));
    }

    /** @test */
    public function it_propagates_time_budget_validation_errors(): void
    {
        $result = new \Wtyd\GitHooks\Configuration\ValidationResult();
        \Wtyd\GitHooks\Configuration\OptionsConfiguration::fromArray([
            'time-budget' => ['warn-after' => 300, 'fail-after' => 120],
        ], $result);

        $this->assertTrue($result->hasErrors());
    }

    /** @test */
    public function time_budget_is_not_an_unknown_key(): void
    {
        $result = new \Wtyd\GitHooks\Configuration\ValidationResult();
        \Wtyd\GitHooks\Configuration\OptionsConfiguration::fromArray([
            'time-budget' => ['warn-after' => 60],
        ], $result);

        $this->assertEmpty($result->getWarnings());
    }

    /** @test */
    public function with_overrides_replaces_time_budget(): void
    {
        $original = new \Wtyd\GitHooks\Configuration\OptionsConfiguration(
            false,
            1,
            null,
            'full',
            '',
            [],
            new \Wtyd\GitHooks\Configuration\TimeBudgetConfiguration(120, 300)
        );

        $override = new \Wtyd\GitHooks\Configuration\TimeBudgetConfiguration(60, 180);
        $result = $original->withOverrides(null, null, $override);

        $this->assertSame(60, $result->getTimeBudget()->getWarnAfter());
        $this->assertSame(180, $result->getTimeBudget()->getFailAfter());
    }

    /** @test */
    public function with_overrides_keeps_time_budget_when_override_is_null(): void
    {
        $original = new \Wtyd\GitHooks\Configuration\OptionsConfiguration(
            false,
            1,
            null,
            'full',
            '',
            [],
            new \Wtyd\GitHooks\Configuration\TimeBudgetConfiguration(120, 300)
        );

        $result = $original->withOverrides(null, null);

        $this->assertNotNull($result->getTimeBudget());
        $this->assertSame(120, $result->getTimeBudget()->getWarnAfter());
    }

    /** @test */
    public function with_overrides_disable_time_budget_clears_it(): void
    {
        $original = new \Wtyd\GitHooks\Configuration\OptionsConfiguration(
            false,
            1,
            null,
            'full',
            '',
            [],
            new \Wtyd\GitHooks\Configuration\TimeBudgetConfiguration(120, 300)
        );

        $result = $original->withOverrides(null, null, null, true);

        $this->assertNull($result->getTimeBudget());
    }

    // ========================================================================
    // memory-budget, allocator and stats options (v3.3 — gh-48)
    // ========================================================================

    /** @test */
    public function it_parses_memory_budget_with_warn_and_fail_above(): void
    {
        $result = new ValidationResult();
        $options = OptionsConfiguration::fromArray([
            'memory-budget' => ['warn-above' => 3500, 'fail-above' => 3900],
        ], $result);

        $this->assertFalse($result->hasErrors());
        $budget = $options->getMemoryBudget();
        $this->assertNotNull($budget);
        $this->assertSame(3500, $budget->getWarnAbove());
        $this->assertSame(3900, $budget->getFailAbove());
    }

    /** @test */
    public function it_defaults_memory_budget_to_null(): void
    {
        $options = new OptionsConfiguration();
        $this->assertNull($options->getMemoryBudget());
    }

    /** @test */
    public function it_parses_allocator_fifo_default(): void
    {
        $options = new OptionsConfiguration();
        $this->assertSame('fifo', $options->getAllocator());
    }

    /** @test */
    public function it_parses_allocator_greedy(): void
    {
        $result = new ValidationResult();
        $options = OptionsConfiguration::fromArray(['allocator' => 'greedy'], $result);

        $this->assertFalse($result->hasErrors());
        $this->assertSame('greedy', $options->getAllocator());
    }

    /** @test */
    public function it_reports_error_for_invalid_allocator(): void
    {
        $result = new ValidationResult();
        $options = OptionsConfiguration::fromArray(['allocator' => 'random'], $result);

        $this->assertTrue($result->hasErrors());
        $errorText = $result->getErrors()[0];
        $this->assertStringContainsString("'allocator'", $errorText);
        $this->assertStringContainsString('must be one of', $errorText);
        $this->assertStringContainsString('fifo', $errorText);
        $this->assertStringContainsString('greedy', $errorText);
        $this->assertStringContainsString("got 'random'", $errorText);
        // Kills the ReturnRemoval mutant on `return FIFO;` after error:
        // without the early return, the invalid value would propagate.
        $this->assertSame('fifo', $options->getAllocator());
    }

    /** @test */
    public function it_reports_error_for_non_string_allocator_with_value_in_message(): void
    {
        // Kills the Ternary swap and CastString removal mutants on
        // `$shown = is_string($value) ? $value : (string) $value`:
        // without the cast, non-string values would not appear in the
        // error message (or would error trying to interpolate).
        $result = new ValidationResult();
        $options = OptionsConfiguration::fromArray(['allocator' => 123], $result);

        $this->assertTrue($result->hasErrors());
        $errorText = $result->getErrors()[0];
        $this->assertStringContainsString("'allocator'", $errorText);
        $this->assertStringContainsString("got '123'", $errorText);
        $this->assertSame('fifo', $options->getAllocator());
    }

    /** @test */
    public function it_parses_stats_true(): void
    {
        $result = new ValidationResult();
        $options = OptionsConfiguration::fromArray(['stats' => true], $result);

        $this->assertFalse($result->hasErrors());
        $this->assertTrue($options->isStats());
    }

    /** @test */
    public function it_defaults_stats_to_false(): void
    {
        $options = new OptionsConfiguration();
        $this->assertFalse($options->isStats());
    }

    /** @test */
    public function it_reports_error_for_non_boolean_stats(): void
    {
        $result = new ValidationResult();
        $options = OptionsConfiguration::fromArray(['stats' => 'yes'], $result);

        $this->assertTrue($result->hasErrors());
        $errorText = $result->getErrors()[0];
        $this->assertStringContainsString("'stats'", $errorText);
        $this->assertStringContainsString('must be a boolean', $errorText);
        // Kills the FalseValue mutant on `return false;` after invalid
        // stats: without the explicit false, the truthy default would
        // enable stats even on invalid input.
        $this->assertFalse($options->isStats());
    }

    /** @test */
    public function memory_allocator_and_stats_are_not_unknown_keys(): void
    {
        $result = new ValidationResult();
        OptionsConfiguration::fromArray([
            'memory-budget' => ['warn-above' => 1000],
            'allocator' => 'fifo',
            'stats' => false,
        ], $result);

        $this->assertEmpty($result->getWarnings());
    }

    /** @test */
    public function with_overrides_applies_memory_budget(): void
    {
        $original = new OptionsConfiguration();
        $newBudget = new \Wtyd\GitHooks\Configuration\MemoryBudgetConfiguration(2000, 3000);

        $overridden = $original->withOverrides(null, null, null, false, $newBudget);

        $this->assertNotNull($overridden->getMemoryBudget());
        $this->assertSame(2000, $overridden->getMemoryBudget()->getWarnAbove());
    }

    /** @test */
    public function with_overrides_preserves_existing_memory_budget_when_no_new_value_given(): void
    {
        // Kills the Coalesce mutant on `($memoryBudget ?? $this->memoryBudget)`:
        // dropping either operand would either lose the override or lose
        // the fallback to the existing value.
        $existing = new \Wtyd\GitHooks\Configuration\MemoryBudgetConfiguration(1500, 2500);
        $original = new OptionsConfiguration(
            false,
            1,
            null,
            'full',
            '',
            [],
            null,
            $existing
        );

        $overridden = $original->withOverrides(null, null, null, false, null, false);

        $this->assertNotNull($overridden->getMemoryBudget());
        $this->assertSame(1500, $overridden->getMemoryBudget()->getWarnAbove());
        $this->assertSame(2500, $overridden->getMemoryBudget()->getFailAbove());
    }

    /**
     * @test
     * Kills OptionsConfiguration:392 Coalesce swap
     * (`$memoryBudget ?? $this->memoryBudget` → `$this->memoryBudget ?? $memoryBudget`).
     *
     * The two existing tests (existing-only and override-only) cannot detect
     * this mutation: when one operand is null, both `null ?? X` and `X ?? null`
     * resolve to the same X. Only when BOTH operands are non-null do the
     * expressions disagree — original returns the OVERRIDE (CLI/flow wins
     * over global), mutant returns the EXISTING (current swallows the
     * override and the cascade silently loses CLI flags).
     */
    public function with_overrides_memory_budget_override_wins_over_existing(): void
    {
        $existing = new \Wtyd\GitHooks\Configuration\MemoryBudgetConfiguration(1500, 2500);
        $override = new \Wtyd\GitHooks\Configuration\MemoryBudgetConfiguration(800, 1200);
        $original = new OptionsConfiguration(
            false,
            1,
            null,
            'full',
            '',
            [],
            null,
            $existing
        );

        $overridden = $original->withOverrides(null, null, null, false, $override);

        $this->assertSame(
            800,
            $overridden->getMemoryBudget()->getWarnAbove(),
            'Override warn-above must win over the existing value (CLI > global cascade)'
        );
        $this->assertSame(
            1200,
            $overridden->getMemoryBudget()->getFailAbove(),
            'Override fail-above must win over the existing value'
        );
    }

    /** @test */
    public function with_overrides_disable_memory_budget_clears_it(): void
    {
        $original = new OptionsConfiguration(
            false,
            1,
            null,
            'full',
            '',
            [],
            null,
            new \Wtyd\GitHooks\Configuration\MemoryBudgetConfiguration(2000, 3000)
        );

        $overridden = $original->withOverrides(null, null, null, false, null, true);

        $this->assertNull($overridden->getMemoryBudget());
    }

    /** @test */
    public function with_overrides_applies_allocator_and_stats(): void
    {
        $original = new OptionsConfiguration();

        $overridden = $original->withOverrides(null, null, null, false, null, false, 'greedy', true);

        $this->assertSame('greedy', $overridden->getAllocator());
        $this->assertTrue($overridden->isStats());
    }

    /** @test */
    public function declared_keys_track_memory_budget_allocator_and_stats(): void
    {
        $result = new ValidationResult();
        $options = OptionsConfiguration::fromArray([
            'memory-budget' => ['warn-above' => 1000],
            'allocator' => 'greedy',
            'stats' => true,
        ], $result);

        $this->assertTrue($options->hasKey('memory-budget'));
        $this->assertTrue($options->hasKey('allocator'));
        $this->assertTrue($options->hasKey('stats'));
    }
}
