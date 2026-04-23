<?php

declare(strict_types=1);

namespace Tests\Unit\Output;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Output\ProgressOutputHandler;

class ProgressOutputHandlerTest extends TestCase
{
    /** @test */
    public function on_job_success_writes_progress_to_stream()
    {
        $stream = fopen('php://temp', 'rw');
        $handler = new ProgressOutputHandler($stream, true);
        $handler->onFlowStart(3);

        $handler->onJobSuccess('phpstan_src', '1.23s');

        rewind($stream);
        $output = stream_get_contents($stream);

        $this->assertStringContainsString('OK', $output);
        $this->assertStringContainsString('phpstan_src', $output);
        $this->assertStringContainsString('1.23s', $output);
        $this->assertStringContainsString('[1/3]', $output);
    }

    /** @test */
    public function on_job_error_writes_ko_to_stream()
    {
        $stream = fopen('php://temp', 'rw');
        $handler = new ProgressOutputHandler($stream, true);
        $handler->onFlowStart(2);

        $handler->onJobError('phpmd_src', '500ms', 'error output');

        rewind($stream);
        $output = stream_get_contents($stream);

        $this->assertStringContainsString('KO', $output);
        $this->assertStringContainsString('phpmd_src', $output);
        $this->assertStringContainsString('[1/2]', $output);
    }

    /** @test */
    public function on_job_skipped_writes_skip_to_stream()
    {
        $stream = fopen('php://temp', 'rw');
        $handler = new ProgressOutputHandler($stream, true);
        $handler->onFlowStart(2);

        $handler->onJobSkipped('phpcs_src', 'no staged files');

        rewind($stream);
        $output = stream_get_contents($stream);

        $this->assertStringContainsString('SKIP', $output);
        $this->assertStringContainsString('phpcs_src', $output);
        $this->assertStringContainsString('no staged files', $output);
    }

    /** @test */
    public function counter_increments_across_events()
    {
        $stream = fopen('php://temp', 'rw');
        $handler = new ProgressOutputHandler($stream, true);
        $handler->onFlowStart(3);

        $handler->onJobSuccess('job1', '1s');
        $handler->onJobError('job2', '2s', 'err');
        $handler->onJobSkipped('job3', 'skipped');

        rewind($stream);
        $output = stream_get_contents($stream);

        $this->assertStringContainsString('[1/3]', $output);
        $this->assertStringContainsString('[2/3]', $output);
        $this->assertStringContainsString('[3/3]', $output);
    }

    /** @test */
    public function flush_writes_summary()
    {
        $stream = fopen('php://temp', 'rw');
        $handler = new ProgressOutputHandler($stream, true);
        $handler->onFlowStart(2);
        $handler->onJobSuccess('job1', '1s');
        $handler->onJobSuccess('job2', '1s');

        $handler->flush();

        rewind($stream);
        $output = stream_get_contents($stream);

        $this->assertStringContainsString('Done.', $output);
        $this->assertStringContainsString('2/2', $output);
    }

    /** @test */
    public function on_job_output_is_silent()
    {
        $stream = fopen('php://temp', 'rw');
        $handler = new ProgressOutputHandler($stream);

        $handler->onJobOutput('job1', 'some output', false);

        rewind($stream);
        $this->assertEmpty(stream_get_contents($stream));
    }

    /** @test */
    public function on_job_start_is_silent()
    {
        $stream = fopen('php://temp', 'rw');
        $handler = new ProgressOutputHandler($stream);

        $handler->onJobStart('job1');

        rewind($stream);
        $this->assertEmpty(stream_get_contents($stream));
    }

    /** @test */
    public function is_silent_when_stream_is_not_tty_and_not_forced()
    {
        // php://temp is not a TTY. Without forceEnabled the handler must not
        // emit anything — consumers parsing stdout (Claude, CI, pipes) get
        // clean output without needing `2>/dev/null`.
        $stream = fopen('php://temp', 'rw');
        $handler = new ProgressOutputHandler($stream, false);

        $handler->onFlowStart(2);
        $handler->onJobSuccess('job1', '1s');
        $handler->onJobError('job2', '2s', 'err');
        $handler->flush();

        rewind($stream);
        $this->assertEmpty(stream_get_contents($stream));
    }

    /** @test */
    public function emits_when_force_enabled_even_if_stream_is_not_tty()
    {
        // -v / verbose flag path: user explicitly asked for progress.
        $stream = fopen('php://temp', 'rw');
        $handler = new ProgressOutputHandler($stream, true);

        $handler->onFlowStart(1);
        $handler->onJobSuccess('job1', '1s');
        $handler->flush();

        rewind($stream);
        $output = stream_get_contents($stream);

        $this->assertStringContainsString('OK', $output);
        $this->assertStringContainsString('Done.', $output);
    }
}
