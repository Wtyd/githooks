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

    /** @test */
    public function it_emits_settings_line_in_text_mode()
    {
        $double = new EmitsConditionsHeaderCommandDouble();
        $double->options = ['format' => 'text'];

        $double->call($this->makeResolution());

        $this->assertCount(1, $double->lines);
        $this->assertSame(
            'Settings: processes=4 (cli) | fail-fast=true (flows.qa.options) | mode=full (default) | time-budget=none (default) | memory-budget=none (default) | allocator=fifo (default) | stats=false (default)',
            $double->lines[0]
        );
        $this->assertSame([], $double->errLines);
    }

    /** @test */
    public function it_appends_flows_line_when_expanded_flows_provided()
    {
        $double = new EmitsConditionsHeaderCommandDouble();
        $double->options = ['format' => 'text'];

        $double->call($this->makeResolution(), ['qa', 'lint']);

        $this->assertCount(2, $double->lines);
        $this->assertStringStartsWith('Settings:', $double->lines[0]);
        $this->assertSame('Flows: qa, lint', $double->lines[1]);
    }

    /** @test */
    public function it_omits_flows_line_for_single_flow_runs()
    {
        $double = new EmitsConditionsHeaderCommandDouble();
        $double->options = ['format' => 'text'];

        $double->call($this->makeResolution(), null);

        $this->assertCount(1, $double->lines);
    }

    /** @test */
    public function it_renders_files_mode_when_input_files_present()
    {
        $double = new EmitsConditionsHeaderCommandDouble();
        $double->options = ['format' => 'text'];

        $inputFiles = $this->createMock(\Wtyd\GitHooks\Execution\InputFilesResolution::class);

        $double->call($this->makeResolution('default', ExecutionMode::FAST), null, $inputFiles);

        $this->assertStringContainsString('mode=files (cli)', $double->lines[0]);
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
        $this->assertCount(2, $double->errLines);
        $this->assertStringStartsWith('Settings:', $double->errLines[0]);
        $this->assertSame('Flows: qa, lint', $double->errLines[1]);
    }

    /** @test */
    public function it_falls_back_to_text_channel_when_format_option_missing()
    {
        $double = new EmitsConditionsHeaderCommandDouble();
        // No format option configured at all — empty string is treated as text
        $double->options = ['format' => ''];

        $double->call($this->makeResolution());

        $this->assertCount(1, $double->lines);
    }

    // ========================================================================
    // time-budget segment (v3.3 item 4)
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

        $this->assertStringContainsString(
            'time-budget=warn-after=120s,fail-after=300s (flows.options)',
            $double->lines[0]
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

        $this->assertStringContainsString(
            'time-budget=warn-after=60s (flows.qa.options)',
            $double->lines[0]
        );
    }

    /** @test */
    public function header_shows_disabled_when_cli_no_time_budget(): void
    {
        $double = new EmitsConditionsHeaderCommandDouble();
        $double->options = ['format' => 'text'];

        $double->call($this->makeResolutionWithTimeBudget(null, 'cli'));

        $this->assertStringContainsString(
            'time-budget=disabled (cli)',
            $double->lines[0]
        );
    }

    /** @test */
    public function header_shows_none_when_unconfigured(): void
    {
        $double = new EmitsConditionsHeaderCommandDouble();
        $double->options = ['format' => 'text'];

        $double->call($this->makeResolutionWithTimeBudget(null, 'default'));

        $this->assertStringContainsString(
            'time-budget=none (default)',
            $double->lines[0]
        );
    }

    // ========================================================================
    // memory-budget / allocator / stats segments (v3.3 — gh-48)
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

        $this->assertStringContainsString(
            'memory-budget=warn-above=3500MB,fail-above=3900MB (flows.options)',
            $double->lines[0]
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

        $this->assertStringContainsString(
            'memory-budget=warn-above=1500MB (flows.qa.options)',
            $double->lines[0]
        );
    }

    /** @test */
    public function header_shows_memory_disabled_when_cli_no_memory_budget(): void
    {
        $double = new EmitsConditionsHeaderCommandDouble();
        $double->options = ['format' => 'text'];

        $double->call($this->makeResolutionWithMemoryBudget(null, 'cli'));

        $this->assertStringContainsString(
            'memory-budget=disabled (cli)',
            $double->lines[0]
        );
    }

    /** @test */
    public function header_shows_memory_none_when_unconfigured(): void
    {
        $double = new EmitsConditionsHeaderCommandDouble();
        $double->options = ['format' => 'text'];

        $double->call($this->makeResolutionWithMemoryBudget(null, 'default'));

        $this->assertStringContainsString(
            'memory-budget=none (default)',
            $double->lines[0]
        );
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

        $this->assertStringContainsString('allocator=greedy (cli)', $double->lines[0]);
        $this->assertStringContainsString('stats=true (cli)', $double->lines[0]);
    }
}
