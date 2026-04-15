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
}
