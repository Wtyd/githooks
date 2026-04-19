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
        ob_start();
        $handler = new DashboardOutputHandler(true);
        $handler->registerJobs(['job1']);
        $handler->onJobStart('job1');
        ob_clean();

        $handler->tick();
        $output = ob_get_clean();

        $this->assertStringContainsString('job1', $output);
    }

    /** @test */
    function tick_in_tty_with_no_running_jobs_produces_no_output()
    {
        ob_start();
        $handler = new DashboardOutputHandler(true);
        $handler->registerJobs(['job1']);
        ob_clean();

        $handler->tick();
        $output = ob_get_clean();

        $this->assertSame('', $output);
    }

    /** @test */
    function on_job_skipped_removes_job_from_queued_state_in_tty_render()
    {
        ob_start();
        $handler = new DashboardOutputHandler(true);
        $handler->registerJobs(['job_a', 'job_b']);
        $handler->onJobSkipped('job_a', 'no files');
        $handler->onJobStart('job_b');
        ob_clean();

        $handler->tick();
        $output = ob_get_clean();

        $this->assertStringNotContainsString('⏺ job_a', $output);
        $this->assertStringContainsString('⏩ job_a', $output);
    }

    /** @test */
    function flush_in_tty_after_dashboard_rendered_clears_it_before_final_results()
    {
        ob_start();
        $handler = new DashboardOutputHandler(true);
        $handler->registerJobs(['job1']);
        $handler->onJobStart('job1');
        $handler->onJobSuccess('job1', '100ms');
        ob_clean();

        $handler->flush();
        $output = ob_get_clean();

        $this->assertStringContainsString("\e[", $output);
        $this->assertStringContainsString('job1 - OK', $output);
    }

    /** @test */
    function flush_in_tty_with_no_dashboard_rendered_does_not_clear()
    {
        $handler = new DashboardOutputHandler(true);
        ob_start();

        $handler->flush();
        $output = ob_get_clean();

        $this->assertSame('', $output);
    }
}
