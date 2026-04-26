<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output;

use Wtyd\GitHooks\Execution\FlowResult;
use Wtyd\GitHooks\Execution\InputFilesResolution;
use Wtyd\GitHooks\Execution\JobResult;

class JsonResultFormatter implements ResultFormatter
{
    public function format(FlowResult $result): string
    {
        $jobs = array_map(function (JobResult $job): array {
            $entry = [
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

            $perJob = $job->getInputFiles();
            if ($perJob !== null) {
                $entry['inputFiles'] = $perJob->toArray();
            }

            return $entry;
        }, $result->getJobResults());

        $inputFiles = $result->getInputFiles();

        $data = [
            'version'       => 2,
            'flow'          => $result->getFlowName(),
            'success'       => $result->isSuccess(),
            'totalTime'     => $result->getTotalTime(),
            'executionMode' => $inputFiles !== null ? 'files' : $result->getExecutionMode(),
            'passed'        => $result->getPassedCount(),
            'failed'        => $result->getFailedCount(),
            'skipped'       => $result->getSkippedCount(),
        ];

        if ($inputFiles !== null) {
            $data['inputFiles'] = $this->buildInputFilesBlock($inputFiles);
        }

        $data['jobs'] = array_values($jobs);

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json !== false ? $json : '{"error": "JSON encoding failed"}';
    }

    /**
     * @return array<string, mixed>
     */
    private function buildInputFilesBlock(InputFilesResolution $inputFiles): array
    {
        $block = [
            'source'        => $inputFiles->getSource(),
            'sourcePath'    => $inputFiles->getSourcePath(),
            'totalProvided' => $inputFiles->getTotalProvided(),
            'totalValid'    => $inputFiles->getTotalValid(),
            'invalid'       => $inputFiles->getInvalid(),
        ];

        if ($inputFiles->hasExcludePatterns()) {
            $block['excludedPatterns']   = $inputFiles->getExcludedPatterns();
            $block['excluded']           = $inputFiles->getExcluded();
            $block['totalAfterExclude']  = $inputFiles->getTotalAfterExclude();
        }

        return $block;
    }

    private function stripAnsi(string $text): string
    {
        return (string) preg_replace('/\x1B(?:\[[0-9;]*[A-Za-z]|\][^\x07]*\x07)|\r/', '', $text);
    }
}
