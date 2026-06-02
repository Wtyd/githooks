<?php

declare(strict_types=1);

namespace Tests\Unit\Output;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Wtyd\GitHooks\Execution\Diagnostics;
use Wtyd\GitHooks\Output\DiagnosticsHeaderEmitter;
use Wtyd\GitHooks\Output\HeaderOptions;

class DiagnosticsHeaderEmitterTest extends TestCase
{
    private DiagnosticsHeaderEmitter $emitter;

    private const TS = '2026-05-13T14:23:08+00:00';

    protected function setUp(): void
    {
        $this->emitter = new DiagnosticsHeaderEmitter();
    }

    private function inCi(): Diagnostics
    {
        return new Diagnostics('3.5.0', 'linux', 'gitlab-ci', 32, null, 1240, 65536, 2.0, 1.5, 1.0);
    }

    private function local(): Diagnostics
    {
        return new Diagnostics('3.5.0', 'linux', null, 8, null, 12000, 16000, 1.2, 0.8, 0.5);
    }

    /** @test */
    public function in_ci_text_emits_multiline_to_stdout(): void
    {
        $out = new BufferedOutput();
        $this->emitter->emit($this->inCi(), self::TS, false, new HeaderOptions('text', false), $out);

        $text = $out->fetch();
        $this->assertStringContainsString('githooks 3.5.0 on linux · gitlab-ci · ' . self::TS, $text);
        $this->assertStringContainsString('cpus', $text);
        $this->assertStringContainsString("\n", $text); // multiline
    }

    /** @test */
    public function local_with_diag_flag_emits_compact_single_line(): void
    {
        $out = new BufferedOutput();
        $this->emitter->emit($this->local(), self::TS, true, new HeaderOptions('text', false), $out);

        $text = trim($out->fetch());
        $this->assertStringStartsWith('githooks 3.5.0 · linux · cpus=8', $text);
        $this->assertStringNotContainsString("\n", $text); // single line
    }

    /** @test */
    public function local_without_diag_flag_emits_nothing(): void
    {
        $out = new BufferedOutput();
        $this->emitter->emit($this->local(), self::TS, false, new HeaderOptions('text', false), $out);

        $this->assertSame('', $out->fetch());
    }

    /** @test */
    public function structured_format_without_show_progress_is_suppressed(): void
    {
        $out = new BufferedOutput();
        // In CI, json format, no --show-progress → suppressed (clean stdout, BUG-5).
        $this->emitter->emit($this->inCi(), self::TS, false, new HeaderOptions('json', false), $out);

        $this->assertSame('', $out->fetch());
    }
}
