<?php

declare(strict_types=1);

namespace Tests\Unit\Output;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Execution\EffectiveOptionsResolution;
use Wtyd\GitHooks\Execution\ExecutionMode;
use Wtyd\GitHooks\Execution\InputFilesResolution;
use Wtyd\GitHooks\Output\ConditionsHeaderEmitter;
use Wtyd\GitHooks\Output\HeaderOptions;

/**
 * Restored unit coverage for the "Settings:" conditions header, ported 1:1 from
 * the deleted `EmitsConditionsHeaderTest` (trait era) onto the extracted
 * {@see ConditionsHeaderEmitter} class (Runner refactor Phase 2c left this class
 * without unit tests).
 *
 * Tabla de factores — ConditionsHeaderEmitter::emit():
 *
 * | Factor                | Clases de equivalencia                                              |
 * |-----------------------|---------------------------------------------------------------------|
 * | format (sink)         | text (→ stdout) · clean-stdout sin progress (→ silencio) ·          |
 * |                       | clean-stdout + progress en SymfonyStyle (→ stderr) ·               |
 * |                       | clean-stdout + progress en buffer plano (→ silencio) · '' (→ text) |
 * | expandedFlows         | null · [] · lista (→ línea 'Flows: ...')                            |
 * | inputFiles            | null (→ mode del trace) · presente (→ mode 'files'/'cli')           |
 * | time/memory budget    | none (default) · disabled (cli+null) · warn-only · warn+fail        |
 * | source de cada opción | default · cli · flows.*.options (siempre entre paréntesis)          |
 *
 * Clase patógena cubierta: cada fila DEBE llevar su `(source)` — incluido
 * `(default)` — y el `=` alinearse en una sola columna.
 */
class ConditionsHeaderEmitterTest extends TestCase
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
     * Emit through a real RoutingBufferedOutput (stdout capture) and return the
     * captured lines.
     *
     * @param string[]|null $expandedFlows
     * @return string[]
     */
    private function emit(
        EffectiveOptionsResolution $resolution,
        ?array $expandedFlows = null,
        ?InputFilesResolution $inputFiles = null,
        string $format = 'text',
        bool $showProgress = false
    ): array {
        $output = new RoutingBufferedOutput();
        (new ConditionsHeaderEmitter())->emit(
            $resolution,
            $expandedFlows,
            $inputFiles,
            new HeaderOptions($format, $showProgress),
            $output
        );
        return $output->lines;
    }

    /**
     * Assert there is exactly one header line matching `<label> = <value> (<source>)`
     * (with any padding).
     *
     * @param string[] $lines
     */
    private function assertHeaderRow(array $lines, string $label, string $value, string $source): void
    {
        $regex = '/^\s*'
            . preg_quote($label, '/')
            . '\s*=\s*'
            . preg_quote($value, '/')
            . '\s+\(' . preg_quote($source, '/') . '\)'
            . '\s*$/m';

        $this->assertMatchesRegularExpression(
            $regex,
            implode("\n", $lines),
            "Expected header row '$label = $value ($source)'"
        );
    }

    /** @test */
    public function it_emits_settings_header_with_one_row_per_option_in_text_mode()
    {
        $lines = $this->emit($this->makeResolution());

        $this->assertSame('Settings:', $lines[0]);
        $this->assertCount(8, $lines); // 'Settings:' + 7 option rows

        $this->assertHeaderRow($lines, 'processes', '4', 'cli');
        $this->assertHeaderRow($lines, 'fail-fast', 'true', 'flows.qa.options');
        $this->assertHeaderRow($lines, 'mode', 'full', 'default');
        $this->assertHeaderRow($lines, 'time-budget', 'none', 'default');
        $this->assertHeaderRow($lines, 'memory-budget', 'none', 'default');
        $this->assertHeaderRow($lines, 'allocator', 'fifo', 'default');
        $this->assertHeaderRow($lines, 'stats', 'false', 'default');
    }

    /** @test */
    public function every_row_carries_its_source_parenthesis_including_default()
    {
        $lines = $this->emit($this->makeResolution());

        foreach (array_slice($lines, 1) as $row) {
            if (strpos($row, '=') === false) {
                continue; // Skip the optional 'Flows:' line.
            }
            $this->assertMatchesRegularExpression(
                '/\([^)]+\)\s*$/',
                $row,
                "Row '$row' must end with a (source) parenthesis"
            );
        }

        $joined = implode("\n", $lines);
        $this->assertStringContainsString('(default)', $joined);
        $this->assertStringContainsString('(cli)', $joined);
        $this->assertStringContainsString('(flows.qa.options)', $joined);
    }

    /** @test */
    public function setting_rows_are_aligned_on_the_equals_sign()
    {
        $lines = $this->emit($this->makeResolution());

        $equalsPositions = [];
        foreach (array_slice($lines, 1) as $row) {
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
        $lines = $this->emit($this->makeResolution(), ['qa', 'lint']);

        $this->assertSame('Settings:', $lines[0]);
        $this->assertSame('Flows: qa, lint', end($lines));
    }

    /** @test */
    public function it_omits_flows_line_for_single_flow_runs()
    {
        $lines = $this->emit($this->makeResolution(), null);

        foreach ($lines as $line) {
            $this->assertStringStartsNotWith('Flows:', $line);
        }
    }

    /** @test */
    public function it_omits_flows_line_for_empty_expanded_flows()
    {
        $lines = $this->emit($this->makeResolution(), []);

        foreach ($lines as $line) {
            $this->assertStringStartsNotWith('Flows:', $line);
        }
    }

    /** @test */
    public function it_renders_files_mode_when_input_files_present()
    {
        $inputFiles = $this->createMock(InputFilesResolution::class);

        $lines = $this->emit($this->makeResolution('default', ExecutionMode::FAST), null, $inputFiles);

        $this->assertHeaderRow($lines, 'mode', 'files', 'cli');
    }

    /** @test */
    public function it_silences_header_for_structured_format_without_show_progress()
    {
        $lines = $this->emit($this->makeResolution(), ['qa', 'lint'], null, 'json', false);

        $this->assertSame([], $lines);
    }

    /** @test */
    public function it_silences_header_for_structured_format_with_progress_on_plain_buffer()
    {
        // Current contract: clean-stdout + --show-progress on a non-SymfonyStyle
        // output is silent (stderr routing only happens on a real SymfonyStyle).
        $lines = $this->emit($this->makeResolution(), ['qa', 'lint'], null, 'json', true);

        $this->assertSame([], $lines);
    }

    /** @test */
    public function it_writes_to_stderr_for_structured_format_with_show_progress_on_symfony_style()
    {
        $stream = fopen('php://memory', 'w+');
        $style = new SymfonyStyle(new ArrayInput([]), new StreamOutput($stream));

        (new ConditionsHeaderEmitter())->emit(
            $this->makeResolution(),
            ['qa', 'lint'],
            null,
            new HeaderOptions('json', true),
            $style
        );

        rewind($stream);
        $captured = stream_get_contents($stream);

        $this->assertStringContainsString('Settings:', $captured);
        $this->assertStringContainsString('Flows: qa, lint', $captured);
    }

    /** @test */
    public function it_falls_back_to_text_channel_when_format_option_is_empty()
    {
        $lines = $this->emit($this->makeResolution(), null, null, '', false);

        $this->assertNotEmpty($lines);
        $this->assertSame('Settings:', $lines[0]);
    }

    // ---- time-budget segment ----

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
    public function header_shows_time_budget_with_warn_and_fail_after()
    {
        $lines = $this->emit($this->makeResolutionWithTimeBudget(
            ['warnAfter' => 120, 'failAfter' => 300],
            'flows.options'
        ));

        $this->assertHeaderRow($lines, 'time-budget', 'warn-after=120s,fail-after=300s', 'flows.options');
    }

    /** @test */
    public function header_shows_time_budget_with_only_warn_after()
    {
        $lines = $this->emit($this->makeResolutionWithTimeBudget(
            ['warnAfter' => 60, 'failAfter' => null],
            'flows.qa.options'
        ));

        $this->assertHeaderRow($lines, 'time-budget', 'warn-after=60s', 'flows.qa.options');
    }

    /** @test */
    public function header_shows_disabled_when_cli_no_time_budget()
    {
        $lines = $this->emit($this->makeResolutionWithTimeBudget(null, 'cli'));

        $this->assertHeaderRow($lines, 'time-budget', 'disabled', 'cli');
    }

    /** @test */
    public function header_shows_none_when_time_budget_unconfigured()
    {
        $lines = $this->emit($this->makeResolutionWithTimeBudget(null, 'default'));

        $this->assertHeaderRow($lines, 'time-budget', 'none', 'default');
    }

    // ---- memory-budget / allocator / stats segment ----

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
    public function header_shows_memory_budget_with_warn_and_fail_above()
    {
        $lines = $this->emit($this->makeResolutionWithMemoryBudget(
            ['warnAbove' => 3500, 'failAbove' => 3900],
            'flows.options'
        ));

        $this->assertHeaderRow($lines, 'memory-budget', 'warn-above=3500MB,fail-above=3900MB', 'flows.options');
    }

    /** @test */
    public function header_shows_memory_budget_with_only_warn_above()
    {
        $lines = $this->emit($this->makeResolutionWithMemoryBudget(
            ['warnAbove' => 1500, 'failAbove' => null],
            'flows.qa.options'
        ));

        $this->assertHeaderRow($lines, 'memory-budget', 'warn-above=1500MB', 'flows.qa.options');
    }

    /** @test */
    public function header_shows_memory_disabled_when_cli_no_memory_budget()
    {
        $lines = $this->emit($this->makeResolutionWithMemoryBudget(null, 'cli'));

        $this->assertHeaderRow($lines, 'memory-budget', 'disabled', 'cli');
    }

    /** @test */
    public function header_shows_memory_none_when_unconfigured()
    {
        $lines = $this->emit($this->makeResolutionWithMemoryBudget(null, 'default'));

        $this->assertHeaderRow($lines, 'memory-budget', 'none', 'default');
    }

    /** @test */
    public function header_shows_allocator_and_stats_with_their_sources()
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

        $lines = $this->emit($resolution);

        $this->assertHeaderRow($lines, 'allocator', 'greedy', 'cli');
        $this->assertHeaderRow($lines, 'stats', 'true', 'cli');
    }
}
