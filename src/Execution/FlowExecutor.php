<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

use Symfony\Component\Process\Process;
use Wtyd\GitHooks\Jobs\JobAbstract;
use Wtyd\GitHooks\Utils\Printer;

/**
 * Orchestrates flow execution: runs jobs respecting processes limit and fail-fast.
 */
class FlowExecutor
{
    private Printer $printer;

    public function __construct(Printer $printer)
    {
        $this->printer = $printer;
    }

    public function execute(FlowPlan $plan): FlowResult
    {
        $start = microtime(true);
        $maxProcesses = $plan->getOptions()->getProcesses();
        $failFast = $plan->getOptions()->isFailFast();
        $jobs = $plan->getJobs();

        if ($maxProcesses <= 1 || count($jobs) <= 1) {
            $results = $this->executeSequential($jobs, $failFast);
        } else {
            $results = $this->executeParallel($jobs, $maxProcesses, $failFast);
        }

        $elapsed = microtime(true) - $start;
        $totalTime = number_format($elapsed, 2) . 's';

        return new FlowResult($plan->getFlowName(), $results, $totalTime);
    }

    /**
     * @param JobAbstract[] $jobs
     * @return JobResult[]
     */
    private function executeSequential(array $jobs, bool $failFast): array
    {
        $results = [];

        foreach ($jobs as $job) {
            $result = $this->runJob($job);
            $results[] = $result;

            if ($failFast && !$result->isSuccess()) {
                break;
            }
        }

        return $results;
    }

    /**
     * @param JobAbstract[] $jobs
     * @return JobResult[]
     */
    private function executeParallel(array $jobs, int $maxProcesses, bool $failFast): array
    {
        $results = [];
        $running = []; // name => ['process' => Process, 'job' => JobAbstract, 'start' => float]
        $queue = $jobs;
        $failFastTriggered = false;

        while (!empty($queue) || !empty($running)) {
            // Fill the pool
            while (!$failFastTriggered && !empty($queue) && count($running) < $maxProcesses) {
                $job = array_shift($queue);
                $command = $job->buildCommand();
                $process = Process::fromShellCommandLine($command);
                $process->setTimeout(null);
                $process->start();
                $running[$job->getName()] = [
                    'process' => $process,
                    'job'     => $job,
                    'start'   => microtime(true),
                ];
            }

            // Check for completion
            foreach ($running as $name => $entry) {
                if (!$entry['process']->isRunning()) {
                    $result = $this->collectResult($entry);
                    $results[] = $result;
                    unset($running[$name]);

                    if ($failFast && !$result->isSuccess()) {
                        $failFastTriggered = true;
                        $this->terminateRunning($running);
                        $running = [];
                        $queue = [];
                        break;
                    }
                }
            }

            if (!empty($running)) {
                usleep(10000); // 10ms poll
            }
        }

        return $results;
    }

    private function runJob(JobAbstract $job): JobResult
    {
        $command = $job->buildCommand();
        $start = microtime(true);

        $process = Process::fromShellCommandLine($command);
        $process->setTimeout(null);
        $process->run();

        return $this->buildResult($job, $process, $start);
    }

    /**
     * @param array{process: Process, job: JobAbstract, start: float} $entry
     */
    private function collectResult(array $entry): JobResult
    {
        return $this->buildResult($entry['job'], $entry['process'], $entry['start']);
    }

    private function buildResult(JobAbstract $job, Process $process, float $start): JobResult
    {
        $elapsed = microtime(true) - $start;
        $time = $this->formatTime($elapsed);
        $exitCode = $process->getExitCode() ?? 1;
        $output = $process->getOutput() . $process->getErrorOutput();
        $fixApplied = $job->isFixApplied($exitCode);
        $success = $exitCode === 0 || $fixApplied;

        if ($job->isIgnoreErrorsOnExit() && !$success) {
            $success = true;
        }

        $displayName = $job->getDisplayName();

        if ($success) {
            $this->printer->success("$displayName - OK. Time: $time");
        } else {
            $this->printer->error("$displayName - KO. Time: $time");
            if (!empty(trim($output))) {
                $this->printer->line($output);
            }
        }

        return new JobResult($job->getName(), $success, $output, $time, $fixApplied);
    }

    /**
     * @param array<string, array{process: Process, job: JobAbstract, start: float}> $running
     */
    private function terminateRunning(array $running): void
    {
        foreach ($running as $entry) {
            if ($entry['process']->isRunning()) {
                $entry['process']->stop(0);
            }
        }
    }

    private function formatTime(float $seconds): string
    {
        if ($seconds < 1) {
            return round($seconds * 1000) . 'ms';
        }
        if ($seconds < 60) {
            return number_format($seconds, 2) . 's';
        }
        $minutes = floor($seconds / 60);
        $secs = (int) ($seconds - ($minutes * 60));
        return "{$minutes}m {$secs}s";
    }
}
