<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

use Symfony\Component\Process\Process;
use Wtyd\GitHooks\Jobs\JobAbstract;
use Wtyd\GitHooks\Output\OutputHandler;
use Wtyd\GitHooks\Execution\ThreadBudgetAllocator;
use Wtyd\GitHooks\Utils\GitStagerInterface;

/**
 * Orchestrates flow execution: runs jobs respecting processes limit and fail-fast.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Orchestrator with sequential+parallel+threading+restaging
 */
class FlowExecutor
{
    private OutputHandler $outputHandler;

    private ?GitStagerInterface $gitStager;

    private int $peakEstimatedThreads = 0;

    /** @var array<string, int> jobName => estimated threads */
    private array $threadAllocations = [];

    public function __construct(OutputHandler $outputHandler, ?GitStagerInterface $gitStager = null)
    {
        $this->outputHandler = $outputHandler;
        $this->gitStager = $gitStager;
    }

    public function setOutputHandler(OutputHandler $outputHandler): void
    {
        $this->outputHandler = $outputHandler;
    }

    /**
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Orchestrates thread budget + sequential/parallel dispatch
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) dry-run is a simple mode toggle, not an SRP violation
     */
    public function execute(FlowPlan $plan, bool $dryRun = false): FlowResult
    {
        $start = microtime(true);
        $maxProcesses = $plan->getOptions()->getProcesses();
        $failFast = $plan->getOptions()->isFailFast();
        $jobs = $plan->getJobs();
        $context = $plan->getContext();

        foreach ($jobs as $job) {
            $this->propagateContext($job, $context);
        }

        if ($dryRun) {
            return $this->executeDryRun($plan->getFlowName(), $jobs);
        }

        // Thread budget: distribute cores among jobs
        $this->peakEstimatedThreads = 0;
        $this->threadAllocations = [];

        if ($maxProcesses > 1 && count($jobs) > 1) {
            $allocator = new ThreadBudgetAllocator();
            $budgetPlan = $allocator->allocate($maxProcesses, $jobs);

            foreach ($jobs as $job) {
                $allocation = $budgetPlan->getAllocation($job->getName());
                if ($allocation !== null) {
                    $job->applyThreadLimit($allocation);
                    $this->threadAllocations[$job->getName()] = $allocation;
                }
            }

            $maxProcesses = $budgetPlan->getMaxParallelJobs();
        } else {
            // Sequential: each job uses its capability default or 1
            foreach ($jobs as $job) {
                $cap = $job->getThreadCapability();
                $this->threadAllocations[$job->getName()] = $cap !== null ? $cap->getDefaultThreads() : 1;
            }
        }

        if ($maxProcesses <= 1 || count($jobs) <= 1) {
            $results = $this->executeSequential($jobs, $failFast);
        } else {
            $results = $this->executeParallel($jobs, $maxProcesses, $failFast);
        }

        $this->outputHandler->flush();

        $elapsed = microtime(true) - $start;
        $totalTime = number_format($elapsed, 2) . 's';
        $budget = $plan->getOptions()->getProcesses();

        return new FlowResult($plan->getFlowName(), $results, $totalTime, $this->peakEstimatedThreads, $budget);
    }

    /**
     * @param JobAbstract[] $jobs
     */
    private function executeDryRun(string $flowName, array $jobs): FlowResult
    {
        $results = [];
        foreach ($jobs as $job) {
            $command = $job->buildCommand();
            $this->outputHandler->onJobDryRun($job->getDisplayName(), $command);
            $results[] = new JobResult($job->getName(), true, '', '0ms', false, $command);
        }
        $this->outputHandler->flush();
        return new FlowResult($flowName, $results, '0ms', 0, 0);
    }

    /**
     * @param JobAbstract[] $jobs
     * @return JobResult[]
     */
    private function executeSequential(array $jobs, bool $failFast): array
    {
        $results = [];

        foreach ($jobs as $job) {
            $threads = $this->threadAllocations[$job->getName()] ?? 1;
            $this->peakEstimatedThreads = max($this->peakEstimatedThreads, $threads);

            $result = $this->runJob($job);
            $results[] = $result;

            if ($failFast && !$result->isSuccess()) {
                $this->reportSkipped($jobs, $job);
                break;
            }
        }

        return $results;
    }

    /**
     * @param JobAbstract[] $jobs
     * @return JobResult[]
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Process pool with fail-fast requires multiple control paths
     */
    private function executeParallel(array $jobs, int $maxProcesses, bool $failFast): array
    {
        $results = [];
        $running = []; // name => ['process' => Process, 'job' => JobAbstract, 'start' => float]
        $queue = $jobs;
        $failFastTriggered = false;

        while (!empty($queue) || !empty($running)) {
            // Fill the pool
            $runningCount = count($running);
            while (!$failFastTriggered && !empty($queue) && $runningCount < $maxProcesses) {
                $job = array_shift($queue);
                $runningCount++;
                $command = $job->buildCommand();
                $process = Process::fromShellCommandLine($command);
                $process->setTimeout(null);
                $process->start();
                $running[$job->getName()] = [
                    'process' => $process,
                    'job'     => $job,
                    'start'   => microtime(true),
                ];

                $this->updatePeakThreads($running);
            }

            // Check for completion
            foreach ($running as $name => $entry) {
                if ($entry['process']->isRunning()) {
                    continue;
                }
                $result = $this->collectResult($entry);
                $results[] = $result;
                unset($running[$name]);

                if ($failFast && !$result->isSuccess()) {
                    $failFastTriggered = true;
                    $this->terminateRunning($running);
                    // Collect results from terminated in-flight jobs
                    foreach ($running as $terminatedEntry) {
                        $results[] = $this->collectResult($terminatedEntry);
                    }
                    // Report skipped: remaining queue (never started)
                    foreach ($queue as $skippedJob) {
                        $this->outputHandler->onJobSkipped($skippedJob->getDisplayName(), 'skipped by fail-fast');
                    }
                    $running = [];
                    $queue = [];
                    break;
                }
            }

            if (!empty($running)) {
                usleep(10000); // 10ms poll
            }
        }

        return $results;
    }

    private function propagateContext(JobAbstract $job, ?ExecutionContext $context): void
    {
        if ($context !== null) {
            $job->setExecutionContext($context);
        }
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
     * @param array<string, array{process: Process, job: JobAbstract, start: float}> $running
     */
    private function updatePeakThreads(array $running): void
    {
        $currentThreads = 0;
        foreach (array_keys($running) as $name) {
            $currentThreads += $this->threadAllocations[$name] ?? 1;
        }
        $this->peakEstimatedThreads = max($this->peakEstimatedThreads, $currentThreads);
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

        // Re-stage fixed files so the commit includes the auto-fixes (e.g. phpcbf)
        if ($fixApplied && $this->gitStager !== null) {
            $this->gitStager->stageTrackedFiles();
        }

        $displayName = $job->getDisplayName();

        if ($success) {
            $this->outputHandler->onJobSuccess($displayName, $time);
        } else {
            $this->outputHandler->onJobError($displayName, $time, $output);
        }

        return new JobResult($job->getName(), $success, $output, $time, $fixApplied);
    }

    /**
     * Report remaining jobs as skipped after a fail-fast trigger (sequential mode).
     *
     * @param JobAbstract[] $allJobs
     * @param JobAbstract $failedJob
     */
    private function reportSkipped(array $allJobs, JobAbstract $failedJob): void
    {
        $found = false;
        foreach ($allJobs as $job) {
            if ($job === $failedJob) {
                $found = true;
                continue;
            }
            if ($found) {
                $this->outputHandler->onJobSkipped($job->getDisplayName(), 'skipped by fail-fast');
            }
        }
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
