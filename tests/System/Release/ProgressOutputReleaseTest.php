<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * Release tests for the redesigned output system added in 3.2: stderr
 * progress emission rules (TTY-aware, `--show-progress`, `-v` no longer
 * forces progress), counter accuracy across run+skip jobs, dry-run
 * silence, and the parallel-dashboard streaming fallback when stdout is
 * not a TTY.
 *
 * @group release
 */
class ProgressOutputReleaseTest extends ReleaseTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configPath = self::TESTS_PATH . '/githooks.php';
        $this->configurationFileBuilder->enableV3Mode();
    }

    /** @test */
    public function progress_is_silent_without_tty_and_json_payload_stays_on_stdout(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['ok_job']]])
            ->setV3Jobs([
                'ok_job' => ['type' => 'custom', 'script' => 'true'],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        $stderrPath = self::TESTS_PATH . '/stderr.log';
        passthru("$this->githooks flow qa --format=json --config=$this->configPath 2>$stderrPath", $exitCode);

        $stdout = $this->getActualOutput();
        $stderr = (string) file_get_contents($stderrPath);

        $this->assertNotNull(json_decode($stdout, true), 'stdout should be decodable JSON: ' . $stdout);
        $this->assertSame('', trim($stderr), 'stderr should be silent off a TTY without --show-progress');
    }

    /** @test */
    public function show_progress_flag_forces_progress_on_stderr_even_without_tty(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['ok_job']]])
            ->setV3Jobs([
                'ok_job' => ['type' => 'custom', 'script' => 'true'],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        $stderrPath = self::TESTS_PATH . '/stderr.log';
        passthru("$this->githooks flow qa --format=json --show-progress --config=$this->configPath 2>$stderrPath", $exitCode);

        $stdout = $this->getActualOutput();
        $stderr = (string) file_get_contents($stderrPath);

        $this->assertNotNull(json_decode($stdout, true), 'stdout should be decodable JSON: ' . $stdout);
        $this->assertStringContainsString('OK', $stderr);
        $this->assertStringContainsString('Done.', $stderr);
    }

    /** @test */
    public function verbose_flag_no_longer_forces_progress_on_stderr(): void
    {
        // Regression: in pre-3.2 drafts, `-v` was wired to force progress.
        // Replaced by a dedicated `--show-progress` flag to free `-v` for
        // its Symfony-standard use.
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['ok_job']]])
            ->setV3Jobs([
                'ok_job' => ['type' => 'custom', 'script' => 'true'],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        $stderrPath = self::TESTS_PATH . '/stderr.log';
        passthru("$this->githooks flow qa --format=json -v --config=$this->configPath 2>$stderrPath", $exitCode);

        $stdout = $this->getActualOutput();
        $stderr = (string) file_get_contents($stderrPath);

        $this->assertNotNull(json_decode($stdout, true), 'stdout should be decodable JSON: ' . $stdout);
        $this->assertStringNotContainsString('OK', $stderr, '`-v` must no longer emit OK progress lines; use --show-progress');
        $this->assertStringNotContainsString('Done.', $stderr, '`-v` must no longer emit Done. summary');
    }

    /** @test */
    public function progress_counter_spans_mixed_run_and_skip_jobs(): void
    {
        // Three jobs: one passes, one fails, one gets skipped by fail-fast.
        // The stderr progress must show [1/3], [2/3], [3/3] and a final
        // "Done. 3/3 completed." — covering both emitted and skipped slots
        // without overrunning the denominator.
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['ok_job', 'fail_job', 'never_job']]])
            ->setV3Jobs([
                'ok_job'    => ['type' => 'custom', 'script' => 'true'],
                'fail_job'  => ['type' => 'custom', 'script' => 'exit 1'],
                'never_job' => ['type' => 'custom', 'script' => 'echo never'],
            ]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        $stderrPath = self::TESTS_PATH . '/stderr.log';
        passthru(
            "$this->githooks flow qa --fail-fast --format=json --show-progress --config=$this->configPath 2>$stderrPath",
            $exitCode
        );

        $stderr = (string) file_get_contents($stderrPath);

        $this->assertStringContainsString('[1/3]', $stderr, 'first counter slot missing');
        $this->assertStringContainsString('[2/3]', $stderr, 'second counter slot missing');
        $this->assertStringContainsString('[3/3]', $stderr, 'third counter slot missing');
        $this->assertStringContainsString('Done. 3/3 completed.', $stderr, 'final banner must cover the full plan');
        $this->assertStringNotContainsString('[4/', $stderr, 'counter must never overrun the denominator');
    }

    /** @test */
    public function dry_run_emits_no_progress_on_stderr(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['ok_job']]])
            ->setV3Jobs(['ok_job' => ['type' => 'custom', 'script' => 'true']]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        $stderrPath = self::TESTS_PATH . '/stderr.log';
        passthru("$this->githooks flow qa --dry-run --format=json --config=$this->configPath 2>$stderrPath", $exitCode);

        $stderr = (string) file_get_contents($stderrPath);
        $this->assertSame('', trim($stderr), 'dry-run should not emit any progress on stderr');
        $this->assertStringNotContainsString('Done.', $stderr, 'the bogus "Done. 0/N completed." banner must be gone');
    }

    /** @test */
    public function dashboard_falls_back_to_streaming_when_stdout_is_not_a_tty(): void
    {
        // Without a TTY (stdout piped to a file here via passthru capture),
        // the parallel dashboard must degrade to append-only streaming: no
        // ANSI cursor-movement, a predictable ⏳ marker per job, and no
        // residual dashboard state after completion.
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['a', 'b', 'c']]])
            ->setV3Jobs([
                'a' => ['type' => 'custom', 'script' => 'true'],
                'b' => ['type' => 'custom', 'script' => 'true'],
                'c' => ['type' => 'custom', 'script' => 'true'],
            ]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks flow qa --processes=2 --config=$this->configPath 2>&1", $exitCode);
        $output = $this->getActualOutput();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('⏳ a', $output);
        $this->assertStringContainsString('a - OK', $output);
        $this->assertDoesNotMatchRegularExpression('/\x1b\[\d+A/', $output, 'non-TTY output must not contain cursor-up escapes');
    }

    /** @test */
    public function dashboard_does_not_repeat_completion_lines_on_a_tty_without_ansi_support(): void
    {
        // BUG-27: the live parallel dashboard redraws with cursor-up escapes
        // (\e[NA). On a stream that reports as a TTY (posix_isatty=true) but
        // does not honour those escapes, the cursor never moves up, so every
        // completed job line was re-appended once per ~200ms refresh tick.
        // The fix gates the live dashboard on `isDecorated() && tty`, so a TTY
        // with colour support disabled degrades to the append-only renderer.
        //
        // We allocate a pseudo-TTY with `script` (posix_isatty=true) and turn
        // colour support off with NO_COLOR (isDecorated=false) — the exact
        // mismatch the bug describes. `--no-ci` keeps the assertion independent
        // of the CI runner forcing decoration back on.
        if (trim((string) shell_exec('command -v script')) === '') {
            $this->markTestSkipped('`script` (util-linux) is required to allocate a pseudo-TTY.');
        }

        $this->configurationFileBuilder
            ->setV3GlobalOptions(['fail-fast' => false, 'processes' => 2])
            ->setV3Flows(['qa' => ['jobs' => ['j_fast', 'j_slow']]])
            ->setV3Jobs([
                'j_fast' => ['type' => 'custom', 'script' => 'true'],
                'j_slow' => ['type' => 'custom', 'script' => 'sleep 1'],
            ]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        $inner = sprintf(
            'NO_COLOR=1 %s flow qa --processes=2 --no-ci --config=%s',
            $this->githooks,
            $this->configPath
        );
        passthru(sprintf('script -qc %s /dev/null 2>&1', escapeshellarg($inner)), $exitCode);

        $output = str_replace("\r", '', $this->getActualOutput());

        $this->assertDoesNotMatchRegularExpression(
            '/\x1b\[\d+A/',
            $output,
            'a TTY without ANSI colour support must use the append-only renderer (no cursor-up escapes)'
        );
        $this->assertSame(
            1,
            substr_count($output, 'j_fast - OK'),
            'each completion line must be printed exactly once, not repeated per refresh tick'
        );
    }
}
