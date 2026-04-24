<?php

declare(strict_types=1);

namespace Tests\Unit\Output;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Output\DashboardOutputHandler;

class DashboardOutputHandlerTest extends TestCase
{
    /** @test */
    function non_tty_mode_prints_start_and_results()
    {
        $handler = new DashboardOutputHandler(false);

        ob_start();
        $handler->registerJobs(['phpstan_src', 'phpcs_src']);
        $handler->onJobStart('phpstan_src');
        $handler->onJobStart('phpcs_src');
        $handler->onJobSuccess('phpstan_src', '1.23s');
        $handler->onJobError('phpcs_src', '500ms', 'error output');
        $handler->flush();
        $output = ob_get_clean();

        $this->assertStringContainsString('⏳ phpstan_src', $output);
        $this->assertStringContainsString('⏳ phpcs_src', $output);
        $this->assertStringContainsString('phpstan_src - OK', $output);
        $this->assertStringContainsString('phpcs_src - KO', $output);
    }

    /** @test */
    function non_tty_mode_shows_error_details_on_flush()
    {
        $handler = new DashboardOutputHandler(false);

        ob_start();
        $handler->registerJobs(['phpstan_src']);
        $handler->onJobStart('phpstan_src');
        $handler->onJobError('phpstan_src', '1s', 'Line 14: Method not found');
        $handler->flush();
        $output = ob_get_clean();

        $this->assertStringContainsString('Method not found', $output);
    }

    /** @test */
    function non_tty_skipped_jobs()
    {
        $handler = new DashboardOutputHandler(false);

        ob_start();
        $handler->registerJobs(['job1']);
        $handler->onJobSkipped('job1', 'no staged files');
        $output = ob_get_clean();

        $this->assertStringContainsString('⏩ job1', $output);
        $this->assertStringContainsString('no staged files', $output);
    }

    /** @test */
    function tick_does_nothing_in_non_tty()
    {
        $handler = new DashboardOutputHandler(false);
        $handler->registerJobs(['job1']);

        ob_start();
        $handler->tick();
        $output = ob_get_clean();

        $this->assertEmpty($output);
    }

    /** @test */
    function on_flow_start_is_silent()
    {
        $handler = new DashboardOutputHandler(false);

        ob_start();
        $handler->onFlowStart(3);
        $output = ob_get_clean();

        $this->assertSame('', $output);
    }

    /** @test */
    function on_job_output_is_silent()
    {
        $handler = new DashboardOutputHandler(false);

        ob_start();
        $handler->onJobOutput('phpstan_src', 'chunk of stdout', false);
        $handler->onJobOutput('phpstan_src', 'chunk of stderr', true);
        $output = ob_get_clean();

        $this->assertSame('', $output);
    }

    /** @test */
    function on_job_dry_run_prints_job_name_and_command()
    {
        $handler = new DashboardOutputHandler(false);

        ob_start();
        $handler->onJobDryRun('phpcs_src', 'vendor/bin/phpcs src');
        $output = ob_get_clean();

        $this->assertStringContainsString('phpcs_src', $output);
        $this->assertStringContainsString('vendor/bin/phpcs src', $output);
    }

    /** @test */
    function flush_without_errors_produces_no_error_frame_in_non_tty()
    {
        $handler = new DashboardOutputHandler(false);

        ob_start();
        $handler->registerJobs(['job1']);
        $handler->onJobStart('job1');
        $handler->onJobSuccess('job1', '100ms');
        $handler->flush();
        $output = ob_get_clean();

        $this->assertStringNotContainsString('┌', $output);
        $this->assertStringNotContainsString('└', $output);
    }

    /** @test */
    function flush_prints_framed_error_for_failed_jobs()
    {
        $handler = new DashboardOutputHandler(false);

        ob_start();
        $handler->registerJobs(['phpstan_src']);
        $handler->onJobStart('phpstan_src');
        $handler->onJobError('phpstan_src', '1s', "src/User.php:14\n  Method not found");
        $handler->flush();
        $output = ob_get_clean();

        $this->assertStringContainsString('phpstan_src', $output);
        $this->assertStringContainsString('Method not found', $output);
        $this->assertStringContainsString('┌', $output);
        $this->assertStringContainsString('└', $output);
    }

    /** @test */
    function flush_skips_frame_when_error_output_is_blank()
    {
        $handler = new DashboardOutputHandler(false);

        ob_start();
        $handler->registerJobs(['job1']);
        $handler->onJobStart('job1');
        $handler->onJobError('job1', '1s', '   ');
        $handler->flush();
        $output = ob_get_clean();

        $this->assertStringNotContainsString('┌', $output);
    }

    // ========================================================================
    // TTY-mode mutation coverage
    // ========================================================================

    /** @test */
    function tick_in_tty_renders_when_jobs_are_running()
    {
        $stream = fopen('php://memory', 'rw');
        $handler = new DashboardOutputHandler(true, $stream);
        $handler->registerJobs(['job1']);
        $handler->onJobStart('job1');
        ftruncate($stream, 0);
        rewind($stream);

        $handler->tick();

        $this->assertStringContainsString('job1', $this->readStream($stream));
    }

    /** @test */
    function tick_in_tty_with_no_running_jobs_produces_no_output()
    {
        $stream = fopen('php://memory', 'rw');
        $handler = new DashboardOutputHandler(true, $stream);
        $handler->registerJobs(['job1']);
        ftruncate($stream, 0);
        rewind($stream);

        $handler->tick();

        $this->assertSame('', $this->readStream($stream));
    }

    /** @test */
    function on_job_skipped_removes_job_from_queued_state_in_tty_render()
    {
        $stream = fopen('php://memory', 'rw');
        $handler = new DashboardOutputHandler(true, $stream);
        $handler->registerJobs(['job_a', 'job_b']);
        $handler->onJobSkipped('job_a', 'no files');
        $handler->onJobStart('job_b');
        ftruncate($stream, 0);
        rewind($stream);

        $handler->tick();
        $output = $this->readStream($stream);

        $this->assertStringNotContainsString('⏺ job_a', $output);
        $this->assertStringContainsString('⏩ job_a', $output);
    }

    /** @test */
    function flush_in_tty_after_dashboard_rendered_clears_it_before_final_results()
    {
        $stream = fopen('php://memory', 'rw');
        $handler = new DashboardOutputHandler(true, $stream);
        $handler->registerJobs(['job1']);
        $handler->onJobStart('job1');
        $handler->onJobSuccess('job1', '100ms');
        ftruncate($stream, 0);
        rewind($stream);

        $handler->flush();
        $output = $this->readStream($stream);

        $this->assertStringContainsString("\e[", $output);
        $this->assertStringContainsString('job1 - OK', $output);
    }

    /** @test */
    function flush_in_tty_with_no_dashboard_rendered_does_not_clear()
    {
        $stream = fopen('php://memory', 'rw');
        $handler = new DashboardOutputHandler(true, $stream);

        $handler->flush();

        $this->assertSame('', $this->readStream($stream));
    }

    /** @test */
    function tty_register_jobs_renders_every_job_in_gray_queued_state()
    {
        $stream = fopen('php://memory', 'rw');
        $handler = new DashboardOutputHandler(true, $stream);

        $handler->registerJobs(['job_a', 'job_b', 'job_c']);

        $output = $this->readStream($stream);
        $this->assertStringContainsString("\e[90m⏺ job_a\e[0m", $output);
        $this->assertStringContainsString("\e[90m⏺ job_b\e[0m", $output);
        $this->assertStringContainsString("\e[90m⏺ job_c\e[0m", $output);
    }

    /** @test */
    function tty_on_job_start_transitions_job_from_queued_to_running_with_timer()
    {
        $stream = fopen('php://memory', 'rw');
        $handler = new DashboardOutputHandler(true, $stream);
        $handler->registerJobs(['job_a', 'job_b']);
        $handler->onJobStart('job_a');
        ftruncate($stream, 0);
        rewind($stream);

        $handler->tick();
        $output = $this->readStream($stream);

        // job_a transitioned: no longer queued, now running with a timer.
        $this->assertStringNotContainsString('⏺ job_a', $output);
        $this->assertStringContainsString('⏳', $output);
        $this->assertStringContainsString('job_a', $output);
        $this->assertMatchesRegularExpression('/job_a \[\x1b\[33m\d+\.\d+s\x1b\[0m\]/', $output, 'running job must render a live timer in [N.Ns] format');
        // job_b remains queued.
        $this->assertStringContainsString('⏺ job_b', $output);
    }

    /** @test */
    function tty_redraw_emits_cursor_up_and_clear_line_matching_rendered_line_count()
    {
        $stream = fopen('php://memory', 'rw');
        $handler = new DashboardOutputHandler(true, $stream);
        // First render: 3 queued lines.
        $handler->registerJobs(['a', 'b', 'c']);
        // Start one job so tick() actually re-renders (tick skips when nothing is running).
        $handler->onJobStart('a');
        ftruncate($stream, 0);
        rewind($stream);

        $handler->tick();
        $output = $this->readStream($stream);

        // clearDashboard emits: cursor-up N + (clear-line + newline) × N + cursor-up N again.
        $this->assertStringContainsString("\033[3A", $output, 'cursor-up must match the 3 previously rendered lines');
        $this->assertSame(3, substr_count($output, "\033[2K"), 'clear-line must fire once per previously rendered line');
    }

    /** @test */
    function tty_successful_job_leaves_final_ok_line_without_queued_or_running_markers()
    {
        $stream = fopen('php://memory', 'rw');
        $handler = new DashboardOutputHandler(true, $stream);
        $handler->registerJobs(['only_job']);
        $handler->onJobStart('only_job');
        $handler->onJobSuccess('only_job', '750ms');
        ftruncate($stream, 0);
        rewind($stream);

        $handler->flush();
        $output = $this->readStream($stream);

        $this->assertStringContainsString('only_job - OK. Time: 750ms', $output);
        $this->assertStringNotContainsString('⏺', $output, 'no job should be shown as queued after flush');
        $this->assertStringNotContainsString('⏳', $output, 'no job should be shown as running after flush');
    }

    /** @test */
    function tty_end_to_end_flow_collapses_to_final_results_only()
    {
        $stream = fopen('php://memory', 'rw');
        $handler = new DashboardOutputHandler(true, $stream);

        $handler->registerJobs(['job_a', 'job_b']);
        $handler->onJobStart('job_a');
        $handler->onJobStart('job_b');
        $handler->onJobSuccess('job_a', '1.20s');
        $handler->onJobError('job_b', '2.00s', '');
        ftruncate($stream, 0);
        rewind($stream);

        $handler->flush();
        $output = $this->readStream($stream);

        $this->assertStringContainsString('job_a - OK. Time: 1.20s', $output);
        $this->assertStringContainsString('job_b - KO. Time: 2.00s', $output);
        $this->assertStringNotContainsString('⏺', $output);
        $this->assertStringNotContainsString('⏳', $output);
    }

    /**
     * @param resource $stream
     */
    private function readStream($stream): string
    {
        rewind($stream);
        return stream_get_contents($stream) ?: '';
    }
}
