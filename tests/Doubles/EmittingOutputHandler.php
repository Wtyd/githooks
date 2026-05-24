<?php

declare(strict_types=1);

namespace Tests\Doubles;

/**
 * Inner OutputHandler that emits a marker line to stdout on every event.
 *
 * Designed to be wrapped by decorator handlers (GitLab/GitHub CI, dashboard,
 * etc.) so a test can drive a sequence of hooks and then assert which
 * markers end up in the captured output and in which order. Each hook
 * writes a single line in a stable format:
 *
 *   marker:start
 *   marker:output(<chunk>)
 *   marker:success
 *   marker:error
 *   marker:skipped(<reason>)
 *
 * Use {@see CapturesStdout::captureStdout()} from the wrapping test to
 * capture stdout, then assert the markers via
 * {@see AssertsOutputBody::assertMarkersInOrder()}.
 */
class EmittingOutputHandler extends NoOpOutputHandler
{
    public function onJobStart(string $jobName): void
    {
        echo "marker:start\n";
    }

    public function onJobOutput(string $jobName, string $chunk, bool $isStderr): void
    {
        echo "marker:output($chunk)\n";
    }

    public function onJobSuccess(string $jobName, string $time): void
    {
        echo "marker:success\n";
    }

    public function onJobError(string $jobName, string $time, string $output): void
    {
        echo "marker:error\n";
    }

    public function onJobSkipped(string $jobName, string $reason): void
    {
        echo "marker:skipped($reason)\n";
    }
}
