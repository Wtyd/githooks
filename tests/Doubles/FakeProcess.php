<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Symfony\Component\Process\Process;

/**
 * Test double for `Symfony\Component\Process\Process` that NEVER spawns a real
 * subprocess. Backs `FakeProcessPool` so unit tests can exercise
 * `FlowExecutor::executeParallel` without paying wallclock time on `sleep`
 * scripts or shelling out to `sh`.
 *
 * Lifecycle:
 *   - Constructed with the exit code and output the consumer should see
 *     once the process is marked finished.
 *   - Starts in `running = true`. The owning `FakeProcessPool` flips the
 *     flag via `markFinished()` according to the test's release schedule.
 *
 * Symfony's Process constructor is invoked with a no-op command so that any
 * inherited behaviour (option resolution, working-directory defaults) stays
 * intact — we just never call `start()`, and we override every method the
 * production code under test actually reads from a Process instance.
 */
class FakeProcess extends Process
{
    private int $programmedExitCode = 0;

    private string $programmedOutput = '';

    private string $programmedErrorOutput = '';

    // Default `true` so Symfony's parent constructor (which transitively
    // calls our isRunning() via setInput/setIdleTimeout) sees the fake as
    // already-terminated and skips its "process is running" guards. The
    // real running state is set AFTER the parent constructor returns.
    private bool $finished = true;

    public function __construct(int $exitCode = 0, string $output = '', string $errorOutput = '', bool $startFinished = false)
    {
        // Dummy command; we never start the process.
        parent::__construct(['true']);
        $this->programmedExitCode = $exitCode;
        $this->programmedOutput = $output;
        $this->programmedErrorOutput = $errorOutput;
        $this->finished = $startFinished;
    }

    public function markFinished(): void
    {
        $this->finished = true;
    }

    public function isRunning(): bool
    {
        return !$this->finished;
    }

    public function getExitCode(): ?int
    {
        return $this->finished ? $this->programmedExitCode : null;
    }

    public function getOutput(): string
    {
        return $this->programmedOutput;
    }

    public function getErrorOutput(): string
    {
        return $this->programmedErrorOutput;
    }

    public function getPid(): ?int
    {
        return null;
    }

    /**
     * @param int|float $timeout
     * @param int|null  $signal
     */
    public function stop($timeout = 10, $signal = null): ?int
    {
        $this->finished = true;
        return $this->programmedExitCode;
    }
}
