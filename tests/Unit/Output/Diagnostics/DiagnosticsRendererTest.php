<?php

declare(strict_types=1);

namespace Tests\Unit\Output\Diagnostics;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Execution\Diagnostics;
use Wtyd\GitHooks\Output\Diagnostics\DiagnosticsRenderer;

class DiagnosticsRendererTest extends TestCase
{
    private DiagnosticsRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new DiagnosticsRenderer();
    }

    private function full(): Diagnostics
    {
        return new Diagnostics('3.5.0', 'linux', 'gitlab-ci', 32, null, 1240, 65536, 28.5, 24.1, 21.0);
    }

    /** @test */
    public function multiline_renders_header_and_aligned_columns(): void
    {
        $lines = $this->renderer->renderMultiline($this->full(), '2026-05-13T14:23:08.123+00:00');

        $this->assertSame(
            'githooks 3.5.0 on linux · gitlab-ci · 2026-05-13T14:23:08.123+00:00',
            $lines[0]
        );
        // labels aligned to the longest ("load avg (1/5/15)")
        $this->assertContains('  cpus              = 32 (cgroup limit: none)', $lines);
        $this->assertContains('  mem available     = 1240 MB / 65536 MB', $lines);
        $this->assertContains('  load avg (1/5/15) = 28.50 / 24.10 / 21.00', $lines);
    }

    /** @test */
    public function compact_renders_a_single_dense_line(): void
    {
        $line = $this->renderer->renderCompact(
            new Diagnostics('3.5.0', 'linux', null, 8, null, 12403, 65536, 1.2, 0.8, 0.5),
            '2026-05-13T14:23:08+00:00'
        );

        $this->assertSame(
            'githooks 3.5.0 · linux · cpus=8 (cgroup limit: none) · mem=12403 MB / 65536 MB · load=1.20 / 0.80 / 0.50 · 2026-05-13T14:23:08+00:00',
            $line
        );
    }

    /** @test */
    public function null_fields_render_as_na_without_breaking(): void
    {
        // Windows: no memory, no load, cgroup-limited CPU.
        $d = new Diagnostics('3.5.0', 'windows', null, 4, 2, null, null, null, null, null);

        $lines = $this->renderer->renderMultiline($d, '2026-05-13T14:23:08+00:00');

        $this->assertContains('  cpus              = 4 (cgroup limit: 2)', $lines);
        $this->assertContains('  mem available     = n/a', $lines);
        $this->assertContains('  load avg (1/5/15) = n/a', $lines);
    }
}
