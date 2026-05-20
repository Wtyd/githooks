<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output;

use Wtyd\GitHooks\Configuration\Deprecation;
use Wtyd\GitHooks\Configuration\ValidationResult;
use Wtyd\GitHooks\Execution\EffectiveOptionsResolution;
use Wtyd\GitHooks\Execution\FlowResult;
use Wtyd\GitHooks\Execution\InputFilesResolution;
use Wtyd\GitHooks\Execution\JobResult;
use Wtyd\GitHooks\Execution\Memory\MemoryStats;
use Wtyd\GitHooks\Execution\MemoryBudgetState;
use Wtyd\GitHooks\Execution\TimeBudgetState;

class JsonResultFormatter implements ResultFormatter
{
    public function format(FlowResult $result): string
    {
        $jobs = array_map(function (JobResult $job): array {
            $entry = [
                'name'              => $job->getJobName(),
                'type'              => $job->getType(),
                'success'           => $job->isSuccess(),
                'time'              => $job->getExecutionTime(),
                'duration'          => $job->getDurationSeconds(),
                'exitCode'          => $job->getExitCode(),
                'output'            => $this->stripAnsi($job->getOutput()),
                'fixApplied'        => $job->isFixApplied(),
                'command'           => $job->getCommand(),
                'paths'             => $job->getPaths(),
                'skipped'           => $job->isSkipped(),
                'skipReason'        => $job->getSkipReason(),
                'threshold'         => $this->buildThresholdBlock($job),
                'memoryReserved'    => $job->getMemoryReserved(),
                'memoryPeak'        => $job->getMemoryPeak(),
                'memoryThreshold'   => $this->buildMemoryThresholdBlock($job),
                'killedReason'      => $job->getKilledReason(),
            ];

            $perJob = $job->getInputFiles();
            if ($perJob !== null) {
                $entry['inputFiles'] = $perJob->toArray();
            }

            // FEAT-3: emit `needs` only when the entry actually declared
            // dependencies. Empty arrays would clutter the schema for the
            // common case where no `needs` are used (D5).
            $needs = $job->getNeeds();
            if ($needs !== []) {
                $entry['needs'] = $needs;
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
            'timeBudget'    => $this->buildTimeBudgetBlock($result->getTimeBudgetState()),
            'memoryBudget'  => $this->buildMemoryBudgetBlock($result->getMemoryBudgetState()),
            'stats'         => $this->buildStatsBlock($result->getMemoryStats()),
        ];

        $expandedFlows = $result->getExpandedFlows();
        if ($expandedFlows !== null) {
            $data['flows'] = array_values($expandedFlows);
        }

        $effectiveOptions = $result->getEffectiveOptions();
        if ($effectiveOptions !== null) {
            $data['effectiveOptions'] = $this->buildEffectiveOptionsBlock($effectiveOptions);
        }

        if ($inputFiles !== null) {
            $data['inputFiles'] = $this->buildInputFilesBlock($inputFiles);
        }

        $validation = $result->getConfigValidation();
        $data['warnings']     = $this->buildWarningsBlock($validation);
        $data['deprecations'] = $this->buildDeprecationsBlock($validation);

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

    /**
     * @return array<string, array{value: mixed, source: string}>
     */
    private function buildEffectiveOptionsBlock(EffectiveOptionsResolution $resolution): array
    {
        return $resolution->getTrace();
    }

    /**
     * Build the per-job `threshold` block under the explicit-null pattern (CON-005):
     *  - null when no warn-after / fail-after configured for the job.
     *  - object with `warnAfter`, `failAfter`, `warned`, `failed`, `reason` otherwise.
     *    `reason` is a string when warned/failed is true, null when both are false.
     *
     * @return array<string, mixed>|null
     */
    private function buildThresholdBlock(JobResult $job): ?array
    {
        if (!$job->hasThreshold()) {
            return null;
        }

        $state = $job->getThresholdState();
        $warned = $state === JobResult::THRESHOLD_WARNED;
        $failed = $state === JobResult::THRESHOLD_FAILED;

        return [
            'warnAfter' => $job->getConfiguredWarnAfter(),
            'failAfter' => $job->getConfiguredFailAfter(),
            'warned'    => $warned,
            'failed'    => $failed,
            'reason'    => $job->getThresholdReason(),
        ];
    }

    /**
     * Build the root `timeBudget` block under the explicit-null pattern (CON-005):
     *  - null when no time-budget configured for the flow (or thresholds disabled).
     *  - object with `warnAfter`, `failAfter`, `totalJobDuration`, `warned`, `failed` otherwise.
     *
     * @return array<string, mixed>|null
     */
    private function buildTimeBudgetBlock(?TimeBudgetState $state): ?array
    {
        if ($state === null) {
            return null;
        }

        return [
            'warnAfter'        => $state->getWarnAfter(),
            'failAfter'        => $state->getFailAfter(),
            'totalJobDuration' => $state->getTotalJobDuration(),
            'warned'           => $state->isWarned(),
            'failed'           => $state->isFailed(),
        ];
    }

    private function stripAnsi(string $text): string
    {
        return (string) preg_replace('/\x1B(?:\[[0-9;]*[A-Za-z]|\][^\x07]*\x07)|\r/', '', $text);
    }

    /**
     * Build the per-job `memoryThreshold` block under the explicit-null pattern (REQ-041):
     *  - null when no memory threshold was declared.
     *  - object with warnAbove/failAbove/warned/failed/reason otherwise.
     *
     * @return array<string, mixed>|null
     */
    private function buildMemoryThresholdBlock(JobResult $job): ?array
    {
        if (!$job->hasMemoryThreshold()) {
            return null;
        }

        return [
            'warnAbove' => $job->getConfiguredMemoryWarn(),
            'failAbove' => $job->getConfiguredMemoryFail(),
            'warned'    => $job->isMemoryWarned(),
            'failed'    => $job->isMemoryFailed(),
            'reason'    => $job->getMemoryThresholdReason(),
        ];
    }

    /**
     * Build the root `memoryBudget` block under the explicit-null pattern (REQ-039).
     *
     * @return array<string, mixed>|null
     */
    private function buildMemoryBudgetBlock(?MemoryBudgetState $state): ?array
    {
        if ($state === null) {
            return null;
        }

        return [
            'warnAbove'        => $state->getWarnAbove(),
            'failAbove'        => $state->getFailAbove(),
            'peakObserved'     => $state->getPeakObserved(),
            'peakAtSecond'     => $state->getPeakAtSecond(),
            'peakAttribution'  => $this->serializeAttribution($state->getPeakAttribution()),
            'warned'           => $state->isWarned(),
            'failed'           => $state->isFailed(),
        ];
    }

    /**
     * Build the root `stats` block (REQ-040). Sub-block `cores` is always
     * emitted when stats are active because it is deterministic from the
     * schedule; sub-block `memory` is emitted only when the sampler
     * actually produced data.
     *
     * @return array<string, mixed>|null
     */
    private function buildStatsBlock(?MemoryStats $stats): ?array
    {
        if ($stats === null) {
            return null;
        }

        $block = [
            'cores' => [
                'limit'    => $stats->getCoresLimit(),
                'flowPeak' => [
                    'value'        => $stats->getCoresPeak(),
                    'atSecond'     => $stats->getCoresPeakAtSecond(),
                    'jobsInFlight' => $stats->getCoresPeakJobs(),
                ],
            ],
        ];

        if ($stats->isSamplerActive()) {
            $block['memory'] = [
                'flowPeak' => [
                    'value'        => $stats->getMemoryPeak(),
                    'atSecond'     => $stats->getMemoryPeakAtSecond(),
                    'jobsInFlight' => $this->serializeAttribution($stats->getMemoryPeakAttribution()),
                ],
            ];
        }

        return $block;
    }

    /**
     * Serialize a jobName→value map into a list of {name,value} objects so
     * JSON consumers see a stable shape regardless of how PHP renders an
     * empty associative array.
     *
     * @param array<string, int> $attribution
     * @return array<int, array{name: string, value: int}>
     */
    private function serializeAttribution(array $attribution): array
    {
        $list = [];
        foreach ($attribution as $name => $value) {
            $list[] = ['name' => $name, 'value' => $value];
        }
        return $list;
    }

    /**
     * Always-present `warnings` block under the explicit-null pattern: empty
     * array when no validation attached, full string list otherwise. Skipped-job
     * warnings are filtered to match the stderr behaviour (already surfaced by
     * the output handler — listing them again would duplicate noise).
     *
     * @return string[]
     */
    private function buildWarningsBlock(?ValidationResult $validation): array
    {
        if ($validation === null) {
            return [];
        }

        return array_values(array_filter(
            $validation->getWarnings(),
            fn (string $warning): bool => strpos($warning, 'skipped') === false
        ));
    }

    /**
     * Always-present `deprecations` block under the explicit-null pattern:
     * empty array when no validation attached, full structured records otherwise.
     *
     * @return array<int, array<string, string>>
     */
    private function buildDeprecationsBlock(?ValidationResult $validation): array
    {
        if ($validation === null) {
            return [];
        }

        return array_map(
            fn (Deprecation $deprecation): array => $deprecation->toArray(),
            $validation->getDeprecations()
        );
    }
}
