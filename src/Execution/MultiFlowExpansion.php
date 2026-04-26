<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

use Wtyd\GitHooks\Configuration\ConfigurationResult;

/**
 * Pure helpers for spec §4.3 / REQ-003: meta-flow expansion + ordered dedup of
 * flow names, and union of jobs from a list of normal flows with first-occurrence
 * dedup. Lives apart from FlowPreparer to keep the orchestration class focused.
 */
final class MultiFlowExpansion
{
    /**
     * Replace each meta-flow argument by its referenced flows, then dedup normal
     * flow names preserving first-occurrence order.
     *
     * @param string[] $argNames flow / meta-flow names (already validated to exist)
     * @return string[]
     */
    public static function expandFlowNames(array $argNames, ConfigurationResult $config): array
    {
        $expanded = [];
        foreach ($argNames as $name) {
            $flow = $config->getFlow($name);
            if ($flow === null) {
                continue;
            }

            $references = $flow->isMetaFlow() ? $flow->getFlowReferences() : [$name];
            foreach ($references as $ref) {
                if (!in_array($ref, $expanded, true)) {
                    $expanded[] = $ref;
                }
            }
        }
        return $expanded;
    }

    /**
     * Union of jobs from the given normal flows, first-occurrence dedup by name.
     *
     * @param string[] $flowNames
     * @return string[]
     */
    public static function mergeFlowJobs(array $flowNames, ConfigurationResult $config): array
    {
        $jobNames = [];
        foreach ($flowNames as $flowName) {
            $flow = $config->getFlow($flowName);
            if ($flow === null) {
                continue;
            }
            foreach ($flow->getJobs() as $jobName) {
                if (!in_array($jobName, $jobNames, true)) {
                    $jobNames[] = $jobName;
                }
            }
        }
        return $jobNames;
    }
}
