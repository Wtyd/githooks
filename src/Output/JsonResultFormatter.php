<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output;

use Wtyd\GitHooks\Execution\FlowResult;
use Wtyd\GitHooks\Execution\JobResult;

class JsonResultFormatter implements ResultFormatter
{
    public function format(FlowResult $result): string
    {
        $jobs = array_map(function (JobResult $job): array {
            return [
                'name'        => $job->getJobName(),
                'type'        => $job->getType(),
                'success'     => $job->isSuccess(),
                'time'        => $job->getExecutionTime(),
                'exitCode'    => $job->getExitCode(),
                'output'      => $this->stripAnsi($job->getOutput()),
                'fixApplied'  => $job->isFixApplied(),
                'command'     => $job->getCommand(),
                'paths'       => $job->getPaths(),
                'skipped'     => $job->isSkipped(),
                'skipReason'  => $job->getSkipReason(),
            ];
        }, $result->getJobResults());

        $data = [
            'version'       => 2,
            'flow'          => $result->getFlowName(),
            'success'       => $result->isSuccess(),
            'totalTime'     => $result->getTotalTime(),
            'executionMode' => $result->getExecutionMode(),
            'passed'        => $result->getPassedCount(),
            'failed'        => $result->getFailedCount(),
            'skipped'       => $result->getSkippedCount(),
            'jobs'          => array_values($jobs),
        ];

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json !== false ? $json : '{"error": "JSON encoding failed"}';
    }

    private function stripAnsi(string $text): string
    {
        return (string) preg_replace('/\x1B(?:\[[0-9;]*[A-Za-z]|\][^\x07]*\x07)|\r/', '', $text);
    }
}
