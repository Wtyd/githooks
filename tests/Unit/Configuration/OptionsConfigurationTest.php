<?php

declare(strict_types=1);

namespace Tests\Unit\Configuration;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Configuration\ValidationResult;

class OptionsConfigurationTest extends UnitTestCase
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
    public function it_defaults_history_size_to_zero()
    {
        $this->assertSame(0, (new OptionsConfiguration())->getHistorySize());

        $result = new ValidationResult();
        $options = OptionsConfiguration::fromArray(['fail-fast' => false], $result);
        $this->assertSame(0, $options->getHistorySize());
        $this->assertFalse($result->hasErrors());
    }

    /**
     * FEAT-5 · history-size validation factor table.
     *
     * @test
     * @dataProvider historySizeProvider
     * @param mixed $value
     */
    public function it_validates_history_size($value, bool $expectError, int $expectValue)
    {
        $result = new ValidationResult();
        $options = OptionsConfiguration::fromArray(['history-size' => $value], $result);

        $this->assertSame($expectError, $result->hasErrors());
        $this->assertSame($expectValue, $options->getHistorySize());
        if ($expectError) {
            $this->assertStringContainsString('history-size', $result->getErrors()[0]);
        }
    }

    public function historySizeProvider(): array
    {
        return [
            'zero is valid (disabled)'   => [0, false, 0],
            'one is the minimum active'  => [1, false, 1],
            'hundred is valid'           => [100, false, 100],
            'negative is rejected'       => [-1, true, 0],
            'string is rejected'         => ['5', true, 0],
            'float is rejected'          => [1.5, true, 0],
            'null is rejected'           => [null, true, 0],
            'bool is rejected'           => [true, true, 0],
        ];
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
    public function valid_report_formats_constant_exposes_supported_set()
    {
        $this->assertSame(
            ['json', 'junit', 'sarif', 'codeclimate'],
            OptionsConfiguration::VALID_REPORT_FORMATS
        );
    }

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

    /** @test */
    public function cascade_block_keys_returns_global_when_flow_is_null(): void
    {
        $validation = new ValidationResult();
        $globals = OptionsConfiguration::fromArray([
            'executable-prefix' => 'docker exec app',
            'fast-branch-fallback' => 'fast',
            'reports' => ['junit' => 'qa.xml'],
        ], $validation);

        $merged = OptionsConfiguration::cascadeBlockKeysFromFlow(null, $globals);

        $this->assertSame($globals, $merged);
    }

    /** @test */
    public function cascade_block_keys_inherits_global_when_flow_does_not_declare_the_key(): void
    {
        $validation = new ValidationResult();
        $globals = OptionsConfiguration::fromArray([
            'executable-prefix' => 'docker exec app',
            'fast-branch-fallback' => 'fast',
            'reports' => ['junit' => 'qa.xml'],
        ], $validation);
        // Flow declares only an unrelated key; the three block keys must
        // fall through to globals.
        $flow = OptionsConfiguration::fromArray(['fail-fast' => true], $validation);

        $merged = OptionsConfiguration::cascadeBlockKeysFromFlow($flow, $globals);

        $this->assertSame('docker exec app', $merged->getExecutablePrefix());
        $this->assertSame('fast', $merged->getFastBranchFallback());
        $this->assertSame(['junit' => 'qa.xml'], $merged->getReports());
        // The flow's unrelated declaration is preserved verbatim.
        $this->assertTrue($merged->isFailFast());
    }

    /** @test */
    public function cascade_block_keys_lets_flow_override_each_key_individually(): void
    {
        $validation = new ValidationResult();
        $globals = OptionsConfiguration::fromArray([
            'executable-prefix' => 'docker exec app',
            'fast-branch-fallback' => 'fast',
            'reports' => ['junit' => 'qa.xml'],
        ], $validation);
        // Flow overrides only fast-branch-fallback; prefix and reports
        // must still come from globals.
        $flow = OptionsConfiguration::fromArray(['fast-branch-fallback' => 'full'], $validation);

        $merged = OptionsConfiguration::cascadeBlockKeysFromFlow($flow, $globals);

        $this->assertSame('full', $merged->getFastBranchFallback());
        $this->assertSame('docker exec app', $merged->getExecutablePrefix());
        $this->assertSame(['junit' => 'qa.xml'], $merged->getReports());
    }
}
