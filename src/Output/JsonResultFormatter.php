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
            $entry = [
                'name'        => $job->getJobName(),
                'success'     => $job->isSuccess(),
                'time'        => $job->getExecutionTime(),
                'output'      => $job->getOutput(),
                'fixApplied'  => $job->isFixApplied(),
            ];
            if ($job->getCommand() !== null) {
                $entry['command'] = $job->getCommand();
            }
            return $entry;
        }, $result->getJobResults());

        $data = [
            'flow'      => $result->getFlowName(),
            'success'   => $result->isSuccess(),
            'totalTime' => $result->getTotalTime(),
            'passed'    => $result->getPassedCount(),
            'failed'    => $result->getFailedCount(),
            'jobs'      => array_values($jobs),
        ];

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json !== false ? $json : '{"error": "JSON encoding failed"}';
    }
}
