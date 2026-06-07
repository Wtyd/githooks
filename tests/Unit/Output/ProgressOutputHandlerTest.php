<?php

declare(strict_types=1);

namespace Tests\Unit\Output;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Output\ProgressOutputHandler;

class ProgressOutputHandlerTest extends UnitTestCase
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

    /**
     * @test
     *
     * Constructed with a non-TTY stream and the default `$forceEnabled`,
     * the handler must be DISABLED (`enabled=false` ⇒ write() short-circuits).
     * The mutated default `true` would force enabled regardless of TTY.
     */
    public function force_enabled_defaults_to_false_so_non_tty_stream_is_silent(): void
    {
        $stream = fopen('php://temp', 'rw');
        // Note: no explicit second argument — relies on the default.
        $handler = new ProgressOutputHandler($stream);

        $handler->onFlowStart(1);
        $handler->onJobSuccess('job1', '1s');
        $handler->flush();

        rewind($stream);
        $this->assertEmpty(stream_get_contents($stream), 'non-TTY stream must produce no output by default');
    }

    /**
     * @test
     *
     * When constructed with `$stream = null` and STDERR is defined (always
     * true in CLI tests), the property must point to STDERR. The mutant
     * `!defined('STDERR')` would always be false in CLI and the fallback
     * `fopen('php://stderr')` branch would run, yielding a different
     * resource handle.
     */
    public function null_stream_picks_STDERR_when_defined(): void
    {
        if (!defined('STDERR')) {
            $this->markTestSkipped('STDERR not defined in this SAPI');
        }
        $handler = new ProgressOutputHandler(null);

        $ref = new \ReflectionClass($handler);
        $streamProp = $ref->getProperty('stream');
        $streamProp->setAccessible(true);
        $stream = $streamProp->getValue($handler);

        $this->assertSame(STDERR, $stream, 'null stream must reuse the STDERR constant');
    }

    /**
     * @test
     *
     * Pin the exact byte sequence written: the OK marker, the job name and
     * duration, the counter, and EXACTLY ONE trailing newline.
     */
    public function write_appends_exactly_one_newline_at_end_of_each_message(): void
    {
        $stream = fopen('php://temp', 'rw');
        $handler = new ProgressOutputHandler($stream, true);
        $handler->onFlowStart(2);

        $handler->onJobSuccess('jobX', '5s');

        rewind($stream);
        $output = stream_get_contents($stream);

        $this->assertSame(
            "  \033[32mOK\033[0m jobX (5s)  [1/2]\n",
            $output,
            'write() emits message followed by EXACTLY one newline'
        );
    }
}
