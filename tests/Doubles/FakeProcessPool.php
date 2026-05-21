<?php

declare(strict_types=1);

namespace Tests\Doubles;

use RuntimeException;
use Wtyd\GitHooks\Execution\ProcessPool;
use Wtyd\GitHooks\Jobs\JobAbstract;

/**
 * Test double for `ProcessPool` that runs no real processes. The production
 * `fillPool` / `pollCompleted` / admission logic is inherited unchanged; we
 * only override `startJob` (to return a `FakeProcess`) and `pollCompleted`
 * (to drive the release schedule and enforce a safety-net iteration cap).
 *
 * Each programmed job declares its terminal outcome (exit code + output) and
 * how many `pollCompleted` calls must occur before the fake process is
 * marked finished. `releaseAfterPolls = 0` means "completes on first poll";
 * any positive integer simulates an in-flight job that the executor's
 * fail-fast / queue-drain logic must process before the job naturally
 * finishes.
 *
 * Safety net: `pollCompleted` lifts a `RuntimeException` after
 * `$maxPollCalls` invocations. If a regression in FlowExecutor turns the
 * outer loop into a livelock (e.g. fail-fast forgets to clear the queue),
 * the test aborts with a clear message instead of hanging the suite.
 */
class FakeProcessPool extends ProcessPool
{
    /** @var array<string, array{exitCode: int, output: string, errorOutput: string, releaseAfterPolls: int}> */
    private array $programmed = [];

    private int $pollCallCount = 0;

    private int $maxPollCalls = 50;

    /**
     * Programme the outcome the executor will observe when this job
     * completes, and how many `pollCompleted` calls the executor has to
     * make before the fake job is reported as finished.
     *
     * `releaseAfterPolls = 0` → completes on the first poll after start.
     * `releaseAfterPolls = N (N > 0)` → simulates a job that is in-flight for
     * N consecutive polls; on the (N+1)-th poll it finishes naturally.
     */
    public function programResult(
        string $jobName,
        int $exitCode = 0,
        string $output = '',
        string $errorOutput = '',
        int $releaseAfterPolls = 0
    ): void {
        $this->programmed[$jobName] = [
            'exitCode'          => $exitCode,
            'output'            => $output,
            'errorOutput'       => $errorOutput,
            'releaseAfterPolls' => max(0, $releaseAfterPolls),
        ];
    }

    /**
     * Lower the safety-net cap (default 50) when a test wants to assert a
     * specific bound. Raising it above the default is intentionally not
     * allowed — if a test legitimately needs more polls, the executor
     * behaviour under test deserves a closer look.
     *
     * @param int $cap must be in (0, 50].
     */
    public function setMaxPollCalls(int $cap): void
    {
        if ($cap <= 0 || $cap > 50) {
            throw new \InvalidArgumentException('maxPollCalls must satisfy 0 < cap <= 50');
        }
        $this->maxPollCalls = $cap;
    }

    /**
     * @return array{process: \Symfony\Component\Process\Process, job: JobAbstract, start: float}
     */
    protected function startJob(JobAbstract $job): array
    {
        $programmed = $this->programmed[$job->getName()] ?? [
            'exitCode'          => 0,
            'output'            => '',
            'errorOutput'       => '',
            'releaseAfterPolls' => 0,
        ];

        $startFinished = $programmed['releaseAfterPolls'] === 0;
        $process = new FakeProcess(
            $programmed['exitCode'],
            $programmed['output'],
            $programmed['errorOutput'],
            $startFinished
        );

        return [
            'process' => $process,
            'job'     => $job,
            'start'   => microtime(true),
        ];
    }

    public function pollCompleted(): array
    {
        $this->pollCallCount++;
        if ($this->pollCallCount > $this->maxPollCalls) {
            throw new RuntimeException(sprintf(
                'FakeProcessPool: pollCompleted() invoked %d times — likely an infinite loop '
                . 'in FlowExecutor::executeParallel (fail-fast did not drain the queue, or a '
                . 'fake job never gets released). Check the release schedule.',
                $this->pollCallCount
            ));
        }

        // Advance the release schedule for jobs still running. When the
        // counter hits zero the FakeProcess flips to finished and the
        // inherited pollCompleted picks it up in this same call.
        foreach ($this->getRunning() as $name => $entry) {
            if (!isset($this->programmed[$name])) {
                continue;
            }
            $remaining = &$this->programmed[$name]['releaseAfterPolls'];
            if ($remaining > 0) {
                $remaining--;
                if ($remaining === 0 && $entry['process'] instanceof FakeProcess) {
                    $entry['process']->markFinished();
                }
            }
            unset($remaining);
        }

        return parent::pollCompleted();
    }

    /**
     * Test accessor: how many times the production loop polled this pool.
     * Useful as a witness that fail-fast short-circuited the loop instead
     * of letting it run to the safety cap.
     */
    public function getPollCallCount(): int
    {
        return $this->pollCallCount;
    }
}
