<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

use Symfony\Component\Process\Process;
use Wtyd\GitHooks\Jobs\JobAbstract;
use Wtyd\GitHooks\Utils\Printer;

class JobExecutor
{
    private Printer $printer;

    public function __construct(Printer $printer)
    {
        $this->printer = $printer;
    }

    public function execute(JobAbstract $job): JobResult
    {
        $command = $job->buildCommand();
        $start = microtime(true);

        $process = Process::fromShellCommandLine($command);
        $process->setTimeout(null);
        $process->run();

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
            if (!empty($output)) {
                $this->printer->line($output);
            }
        }

        $command = $job->buildCommand();

        return new JobResult(
            $job->getName(),
            $success,
            $output,
            $time,
            $fixApplied,
            $command,
            $job->getType(),
            $exitCode,
            $job->getConfiguredPaths()
        );
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
