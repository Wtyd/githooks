<?php

declare(strict_types=1);

namespace Tests\Unit\App\Commands\Concerns;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Execution\EffectiveOptionsResolution;
use Wtyd\GitHooks\Execution\ExecutionMode;

class EmitsConditionsHeaderTest extends TestCase
{
    private function makeResolution(string $modeSource = 'default', string $modeValue = 'full'): EffectiveOptionsResolution
    {
        return new EffectiveOptionsResolution(
            new OptionsConfiguration(),
            $modeValue,
            [
                'processes'     => ['value' => 4, 'source' => 'cli'],
                'failFast'      => ['value' => true, 'source' => 'flows.qa.options'],
                'executionMode' => ['value' => $modeValue, 'source' => $modeSource],
                'memoryBudget'  => ['value' => null, 'source' => 'default'],
                'allocator'     => ['value' => 'fifo', 'source' => 'default'],
                'stats'         => ['value' => false, 'source' => 'default'],
            ]
        );
    }

    /**
     * Assert there is exactly one header line matching `<label> = <value>`
     * (with any padding) and, when $source is non-null and not 'default',
     * a trailing `(<source>)` parenthesis.
     *
     * @param string[] $lines
     */
    private function assertHeaderRow(array $lines, string $label, string $value, ?string $source = null): void
    {
        $regex = '/^\s*'
            . preg_quote($label, '/')
            . '\s*=\s*'
            . preg_quote($value, '/');
        if ($source !== null && $source !== 'default') {
            $regex .= '\s+\(' . preg_quote($source, '/') . '\)';
        }
        $regex .= '\s*$/m';

        $this->assertMatchesRegularExpression(
            $regex,
            implode("\n", $lines),
            "Expected header row '$label = $value" . ($source !== null && $source !== 'default' ? " ($source)" : '') . "'"
        );
    }

    /**
     * Assert the row for $label does NOT include a `(source)` parenthesis
     * (i.e. source was 'default' and was correctly omitted).
     *
     * @param string[] $lines
     */
    private function assertHeaderRowHasNoSource(array $lines, string $label): void
    {
        $regex = '/^\s*' . preg_quote($label, '/') . '\s*=\s*[^\(]+$/m';
        $this->assertMatchesRegularExpression(
            $regex,
            implode("\n", $lines),
            "Row '$label' must not show a source when it comes from 'default'"
        );
    }

    /** @test */
    public function it_emits_settings_header_with_one_row_per_option_in_text_mode()
    {
        $double = new EmitsConditionsHeaderCommandDouble();
        $double->options = ['format' => 'text'];

        $double->call($this->makeResolution());

        // 'Settings:' header + 7 option rows
        $this->assertSame('Settings:', $double->lines[0]);
        $this->assertCount(8, $double->lines);

        $this->assertHeaderRow($double->lines, 'processes', '4', 'cli');
        $this->assertHeaderRow($double->lines, 'fail-fast', 'true', 'flows.qa.options');
        $this->assertHeaderRow($double->lines, 'mode', 'full', 'default');
        $this->assertHeaderRow($double->lines, 'time-budget', 'none', 'default');
        $this->assertHeaderRow($double->lines, 'memory-budget', 'none', 'default');
        $this->assertHeaderRow($double->lines, 'allocator', 'fifo', 'default');
        $this->assertHeaderRow($double->lines, 'stats', 'false', 'default');
        $this->assertSame([], $double->errLines);
    }

    /** @test */
    public function default_source_is_omitted_so_only_overridden_values_show_their_origin()
    {
        $double = new EmitsConditionsHeaderCommandDouble();
        $double->options = ['format' => 'text'];

        $double->call($this->makeResolution());

        // Defaults must NOT carry a `(default)` tail — that's noise.
        $this->assertHeaderRowHasNoSource($double->lines, 'mode');
        $this->assertHeaderRowHasNoSource($double->lines, 'time-budget');
        $this->assertHeaderRowHasNoSource($double->lines, 'memory-budget');
        $this->assertHeaderRowHasNoSource($double->lines, 'allocator');
        $this->assertHeaderRowHasNoSource($double->lines, 'stats');

        // Overridden values must carry their source.
        $joined = implode("\n", $double->lines);
        $this->assertStringContainsString('(cli)', $joined);
        $this->assertStringContainsString('(flows.qa.options)', $joined);
        $this->assertStringNotContainsString('(default)', $joined);
    }

    /** @test */
    public function setting_rows_are_aligned_on_the_equals_sign()
    {
        $double = new EmitsConditionsHeaderCommandDouble();
        $double->options = ['format' => 'text'];

        $double->call($this->makeResolution());

        $equalsPositions = [];
        foreach (array_slice($double->lines, 1) as $row) {
            // Only setting rows (skip 'Flows:' if present)
            if (strpos($row, '=') === false) {
                continue;
            }
            $equalsPositions[] = strpos($row, '=');
        }
        $this->assertNotEmpty($equalsPositions);
        $this->assertCount(1, array_unique($equalsPositions), 'All `=` signs must align in the same column');
    }

    /** @test */
    public function it_appends_flows_line_when_expanded_flows_provided()
    {
        $double = new EmitsConditionsHeaderCommandDouble();
        $double->options = ['format' => 'text'];

        $double->call($this->makeResolution(), ['qa', 'lint']);

        $this->assertSame('Settings:', $double->lines[0]);
        $this->assertSame('Flows: qa, lint', end($double->lines));
    }

    /** @test */
    public function it_omits_flows_line_for_single_flow_runs()
    {
        $double = new EmitsConditionsHeaderCommandDouble();
        $double->options = ['format' => 'text'];

        $double->call($this->makeResolution(), null);

        foreach ($double->lines as $line) {
            $this->assertStringStartsNotWith('Flows:', $line);
        }
    }

    /** @test */
    public function it_renders_files_mode_when_input_files_present()
    {
        $double = new EmitsConditionsHeaderCommandDouble();
        $double->options = ['format' => 'text'];

        $inputFiles = $this->createMock(\Wtyd\GitHooks\Execution\InputFilesResolution::class);

        $double->call($this->makeResolution('default', ExecutionMode::FAST), null, $inputFiles);

        $this->assertHeaderRow($double->lines, 'mode', 'files', 'cli');
    }

    /** @test */
    public function it_silences_header_for_structured_format_without_show_progress()
    {
        $double = new EmitsConditionsHeaderCommandDouble();
        $double->options = ['format' => 'json'];

        $double->call($this->makeResolution(), ['qa', 'lint']);

        $this->assertSame([], $double->lines);
        $this->assertSame([], $double->errLines);
    }

    /** @test */
    public function it_writes_to_stderr_for_structured_format_with_show_progress()
    {
        $double = new EmitsConditionsHeaderCommandDouble();
        $double->options = ['format' => 'json', 'show-progress' => true];

        $double->call($this->makeResolution(), ['qa', 'lint']);

        $this->assertSame([], $double->lines);
        $this->assertGreaterThan(1, count($double->errLines));
        $this->assertSame('Settings:', $double->errLines[0]);
        $this->assertSame('Flows: qa, lint', end($double->errLines));
    }

    /** @test */
    public function it_falls_back_to_text_channel_when_format_option_missing()
    {
        $double = new EmitsConditionsHeaderCommandDouble();
        // No format option configured at all — empty string is treated as text
        $double->options = ['format' => ''];

        $double->call($this->makeResolution());

        $this->assertNotEmpty($double->lines);
        $this->assertSame('Settings:', $double->lines[0]);
    }

    // ========================================================================
    // time-budget segment
    // ========================================================================

    private function makeResolutionWithTimeBudget(?array $value, string $source): EffectiveOptionsResolution
    {
        return new EffectiveOptionsResolution(
            new OptionsConfiguration(),
            'full',
            [
                'processes'     => ['value' => 4, 'source' => 'cli'],
                'failFast'      => ['value' => true, 'source' => 'flows.qa.options'],
                'executionMode' => ['value' => 'full', 'source' => 'default'],
                'timeBudget'    => ['value' => $value, 'source' => $source],
            ]
        );
    }

    /** @test */
    public function header_shows_time_budget_with_warn_and_fail_after(): void
    {
        $double = new EmitsConditionsHeaderCommandDouble();
        $double->options = ['format' => 'text'];

        $double->call($this->makeResolutionWithTimeBudget(
            ['warnAfter' => 120, 'failAfter' => 300],
            'flows.options'
        ));

        $this->assertHeaderRow(
            $double->lines,
            'time-budget',
            'warn-after=120s,fail-after=300s',
            'flows.options'
        );
    }

    /** @test */
    public function header_shows_time_budget_with_only_warn_after(): void
    {
        $double = new EmitsConditionsHeaderCommandDouble();
        $double->options = ['format' => 'text'];

        $double->call($this->makeResolutionWithTimeBudget(
            ['warnAfter' => 60, 'failAfter' => null],
            'flows.qa.options'
        ));

        $this->assertHeaderRow(
            $double->lines,
            'time-budget',
            'warn-after=60s',
            'flows.qa.options'
        );
    }

    /** @test */
    public function header_shows_disabled_when_cli_no_time_budget(): void
    {
        $double = new EmitsConditionsHeaderCommandDouble();
        $double->options = ['format' => 'text'];

        $double->call($this->makeResolutionWithTimeBudget(null, 'cli'));

        $this->assertHeaderRow($double->lines, 'time-budget', 'disabled', 'cli');
    }

    /** @test */
    public function header_shows_none_when_unconfigured(): void
    {
        $double = new EmitsConditionsHeaderCommandDouble();
        $double->options = ['format' => 'text'];

        $double->call($this->makeResolutionWithTimeBudget(null, 'default'));

        $this->assertHeaderRow($double->lines, 'time-budget', 'none', 'default');
        $this->assertHeaderRowHasNoSource($double->lines, 'time-budget');
    }

    // ========================================================================
    // memory-budget / allocator / stats segments
    // ========================================================================

    private function makeResolutionWithMemoryBudget(?array $value, string $source): EffectiveOptionsResolution
    {
        return new EffectiveOptionsResolution(
            new OptionsConfiguration(),
            'full',
            [
                'processes'     => ['value' => 4, 'source' => 'cli'],
                'failFast'      => ['value' => true, 'source' => 'flows.qa.options'],
                'executionMode' => ['value' => 'full', 'source' => 'default'],
                'memoryBudget'  => ['value' => $value, 'source' => $source],
                'allocator'     => ['value' => 'fifo', 'source' => 'default'],
                'stats'         => ['value' => false, 'source' => 'default'],
            ]
        );
    }

    /** @test */
    public function header_shows_memory_budget_with_warn_and_fail_above(): void
    {
        $double = new EmitsConditionsHeaderCommandDouble();
        $double->options = ['format' => 'text'];

        $double->call($this->makeResolutionWithMemoryBudget(
            ['warnAbove' => 3500, 'failAbove' => 3900],
            'flows.options'
        ));

        $this->assertHeaderRow(
            $double->lines,
            'memory-budget',
            'warn-above=3500MB,fail-above=3900MB',
            'flows.options'
        );
    }

    /** @test */
    public function header_shows_memory_budget_with_only_warn_above(): void
    {
        $double = new EmitsConditionsHeaderCommandDouble();
        $double->options = ['format' => 'text'];

        $double->call($this->makeResolutionWithMemoryBudget(
            ['warnAbove' => 1500, 'failAbove' => null],
            'flows.qa.options'
        ));

        $this->assertHeaderRow(
            $double->lines,
            'memory-budget',
            'warn-above=1500MB',
            'flows.qa.options'
        );
    }

    /** @test */
    public function header_shows_memory_disabled_when_cli_no_memory_budget(): void
    {
        $double = new EmitsConditionsHeaderCommandDouble();
        $double->options = ['format' => 'text'];

        $double->call($this->makeResolutionWithMemoryBudget(null, 'cli'));

        $this->assertHeaderRow($double->lines, 'memory-budget', 'disabled', 'cli');
    }

    /** @test */
    public function header_shows_memory_none_when_unconfigured(): void
    {
        $double = new EmitsConditionsHeaderCommandDouble();
        $double->options = ['format' => 'text'];

        $double->call($this->makeResolutionWithMemoryBudget(null, 'default'));

        $this->assertHeaderRow($double->lines, 'memory-budget', 'none', 'default');
        $this->assertHeaderRowHasNoSource($double->lines, 'memory-budget');
    }

    /** @test */
    public function header_shows_allocator_and_stats(): void
    {
        $resolution = new EffectiveOptionsResolution(
            new OptionsConfiguration(),
            'full',
            [
                'processes'     => ['value' => 4, 'source' => 'cli'],
                'failFast'      => ['value' => true, 'source' => 'flows.qa.options'],
                'executionMode' => ['value' => 'full', 'source' => 'default'],
                'memoryBudget'  => ['value' => null, 'source' => 'default'],
                'allocator'     => ['value' => 'greedy', 'source' => 'cli'],
                'stats'         => ['value' => true, 'source' => 'cli'],
            ]
        );

        $double = new EmitsConditionsHeaderCommandDouble();
        $double->options = ['format' => 'text'];
        $double->call($resolution);

        $this->assertHeaderRow($double->lines, 'allocator', 'greedy', 'cli');
        $this->assertHeaderRow($double->lines, 'stats', 'true', 'cli');
    }
}
