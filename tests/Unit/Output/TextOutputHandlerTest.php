<?php

declare(strict_types=1);

namespace Tests\Unit\Output;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Output\TextOutputHandler;
use Wtyd\GitHooks\Utils\Printer;

class TextOutputHandlerTest extends UnitTestCase
{
    use MockeryPHPUnitIntegration;

    /** @test */
    function it_prints_success_immediately()
    {
        $printer = Mockery::mock(Printer::class);
        $printer->shouldReceive('success')->once()->with('phpstan_src - OK. Time: 1.23s');

        $handler = new TextOutputHandler($printer);
        $handler->onJobSuccess('phpstan_src', '1.23s');
    }

    /** @test */
    function it_prints_error_status_immediately_but_buffers_output()
    {
        $printer = Mockery::mock(Printer::class);
        $printer->shouldReceive('error')->once()->with('phpmd_src - KO. Time: 500ms');
        $printer->shouldNotReceive('framedErrorBlock');

        $handler = new TextOutputHandler($printer);
        $handler->onJobError('phpmd_src', '500ms', 'some error output');
    }

    /** @test */
    function it_prints_buffered_errors_on_flush()
    {
        $printer = Mockery::mock(Printer::class);
        $printer->shouldReceive('error')->twice();
        $printer->shouldReceive('emptyLine')->times(3);
        $printer->shouldReceive('framedErrorBlock')->once()->with('phpmd_src', 'error1');
        $printer->shouldReceive('framedErrorBlock')->once()->with('phpstan_src', 'error2');

        $handler = new TextOutputHandler($printer);
        $handler->onJobError('phpmd_src', '500ms', 'error1');
        $handler->onJobError('phpstan_src', '1s', 'error2');
        $handler->flush();
    }

    /** @test */
    function flush_does_nothing_when_no_errors()
    {
        $printer = Mockery::mock(Printer::class);
        $printer->shouldReceive('success')->once();
        $printer->shouldNotReceive('emptyLine');
        $printer->shouldNotReceive('framedErrorBlock');

        $handler = new TextOutputHandler($printer);
        $handler->onJobSuccess('phpcs_all', '200ms');
        $handler->flush();
    }

    /** @test */
    function it_prints_skipped_jobs()
    {
        $printer = Mockery::mock(Printer::class);
        $printer->shouldReceive('line')->once()->with('⏩ phpunit_all (skipped by fail-fast)');

        $handler = new TextOutputHandler($printer);
        $handler->onJobSkipped('phpunit_all', 'skipped by fail-fast');
    }

    /** @test */
    function flush_clears_the_buffer()
    {
        $printer = Mockery::mock(Printer::class);
        $printer->shouldReceive('error')->once();
        $printer->shouldReceive('emptyLine')->twice();
        $printer->shouldReceive('framedErrorBlock')->once();

        $handler = new TextOutputHandler($printer);
        $handler->onJobError('phpmd_src', '500ms', 'error');
        $handler->flush();
        // Second flush should do nothing
        $handler->flush();
    }
}
