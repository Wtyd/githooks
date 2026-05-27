<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

use Wtyd\GitHooks\Configuration\ConfigurationResult;
use Wtyd\GitHooks\Configuration\FlowDependencyGraph;
use Wtyd\GitHooks\Configuration\JobRef;
use Wtyd\GitHooks\Configuration\ValidationResult;

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
        return array_map(
            static fn(JobRef $ref): string => $ref->getTarget(),
            self::mergeFlowJobRefs($flowNames, $config)
        );
    }

    /**
     * Union of job *references* from the given normal flows, preserving each
     * entry's attributes (needs / only-files / exclude-files), with
     * first-occurrence dedup by target.
     *
     * First-occurrence-wins: when the same job is declared in two flows with
     * different attributes, the aggregate keeps the JobRef from the FIRST flow
     * in which it appears (spec §4.3 dedup, extended to FEAT-1/FEAT-3 attrs).
     * Cross-flow `needs` cannot occur — every `needs` target is validated to be
     * a job of its own origin flow at parse time — so the merged refs only ever
     * reference jobs present in the union.
     *
     * @param string[] $flowNames
     * @return JobRef[]
     */
    public static function mergeFlowJobRefs(array $flowNames, ConfigurationResult $config): array
    {
        $refs = [];
        $seen = [];
        foreach ($flowNames as $flowName) {
            $flow = $config->getFlow($flowName);
            if ($flow === null) {
                continue;
            }
            foreach ($flow->getJobReferences() as $ref) {
                if (in_array($ref->getTarget(), $seen, true)) {
                    continue;
                }
                $seen[] = $ref->getTarget();
                $refs[] = $ref;
            }
        }
        return $refs;
    }

    /**
     * Reconstruct the `needs` dependency graph for an aggregate run from its
     * merged JobRefs, so `flows` honours FEAT-3 exactly like `flow`.
     *
     * Cross-flow `needs` is impossible — every `needs` target is validated to
     * be a job of its own origin flow at parse time, and the dedup keeps all
     * those jobs in the union — so building over the deduped refs never yields
     * a missing-target or cycle error. The throwaway ValidationResult keeps any
     * defensive diagnostic out of the user-facing config result.
     *
     * @param JobRef[] $refs deduped, first-occurrence-ordered job references
     */
    public static function buildAggregateGraph(string $aggregateFlowName, array $refs): ?FlowDependencyGraph
    {
        return FlowDependencyGraph::build($aggregateFlowName, $refs, new ValidationResult());
    }
}
