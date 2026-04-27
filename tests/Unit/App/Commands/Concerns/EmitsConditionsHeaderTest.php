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
            'Settings: processes=4 (cli) | fail-fast=true (flows.qa.options) | mode=full (default)',
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
}
