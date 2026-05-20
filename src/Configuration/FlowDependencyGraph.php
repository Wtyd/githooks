<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Configuration;

/**
 * DAG of `needs` relations among the job entries of a single flow (FEAT-3).
 *
 * Responsibilities:
 *  - Validate that every `needs` target is a job declared in the same flow.
 *  - Reject duplicate job declarations (the same name appearing twice in `jobs`).
 *  - Detect cycles of any length (including self-loops) with DFS and report
 *    the offending chain.
 *  - Compute a **stable topological order** that preserves the declaration
 *    order between nodes with no dependency relation (the executor reuses this
 *    order for `processes: 1` and for the parallel admission queue).
 *  - Answer `descendantsOf($name)` for fail-fast logic (skipping only the
 *    transitive dependents of a failing job).
 *
 * Constructed via the static `build()` factory; instances are immutable.
 */
class FlowDependencyGraph
{
    /** @var string[] Stable topological order of job names */
    private array $orderedNames;

    /** @var array<string, string[]> jobName → list of direct dependencies (its `needs`) */
    private array $needsByJob;

    /** @var array<string, string[]> jobName → list of direct dependents (reverse edges) */
    private array $dependentsByJob;

    /**
     * @param string[] $orderedNames
     * @param array<string, string[]> $needsByJob
     * @param array<string, string[]> $dependentsByJob
     */
    private function __construct(array $orderedNames, array $needsByJob, array $dependentsByJob)
    {
        $this->orderedNames = $orderedNames;
        $this->needsByJob = $needsByJob;
        $this->dependentsByJob = $dependentsByJob;
    }

    /**
     * Build the graph for the given flow. Returns null and accumulates errors
     * in `$result` when validation fails (duplicate, missing target, cycle).
     *
     * @param JobRef[] $refs job entries of the flow, in declaration order
     */
    public static function build(string $flowName, array $refs, ValidationResult $result): ?self
    {
        $names = self::collectNames($flowName, $refs, $result);
        if ($names === null) {
            return null;
        }

        $needsByJob = self::collectNeeds($flowName, $refs, $names, $result);
        if ($needsByJob === null) {
            return null;
        }

        $cycle = self::findCycle($needsByJob);
        if ($cycle !== null) {
            $result->addError("Flow '$flowName': 'needs' has a cycle: " . implode(' -> ', $cycle) . '.');
            return null;
        }

        $orderedNames = self::topologicalSort($names, $needsByJob);
        $dependentsByJob = self::buildReverseEdges($names, $needsByJob);

        return new self($orderedNames, $needsByJob, $dependentsByJob);
    }

    /**
     * @param JobRef[] $refs
     * @return string[]|null
     */
    private static function collectNames(string $flowName, array $refs, ValidationResult $result): ?array
    {
        $seen = [];
        foreach ($refs as $ref) {
            $name = $ref->getTarget();
            if (in_array($name, $seen, true)) {
                $result->addError("Flow '$flowName': job '$name' is declared more than once.");
                return null;
            }
            $seen[] = $name;
        }
        return $seen;
    }

    /**
     * @param JobRef[] $refs
     * @param string[] $names
     * @return array<string, string[]>|null
     */
    private static function collectNeeds(
        string $flowName,
        array $refs,
        array $names,
        ValidationResult $result
    ): ?array {
        $needsByJob = [];
        foreach ($refs as $ref) {
            $target = $ref->getTarget();
            $needs = $ref->getNeeds();
            foreach ($needs as $dependency) {
                if (!in_array($dependency, $names, true)) {
                    $result->addError(
                        "Flow '$flowName' job ref '$target': 'needs' references undefined job '$dependency'."
                    );
                    return null;
                }
            }
            $needsByJob[$target] = $needs;
        }
        return $needsByJob;
    }

    /**
     * DFS that returns the offending chain when a cycle is found, or null when
     * the DAG is well-formed. The chain is rendered with the closing node
     * repeated at the end so the user reads it as "A -> B -> A".
     *
     * @param array<string, string[]> $needsByJob
     * @return string[]|null
     */
    private static function findCycle(array $needsByJob): ?array
    {
        $visited = [];     // jobName => true once fully explored
        $stack = [];       // jobName => true while on the current DFS branch

        foreach (array_keys($needsByJob) as $node) {
            if (isset($visited[$node])) {
                continue;
            }
            $path = [];
            $cycle = self::dfsForCycle($node, $needsByJob, $visited, $stack, $path);
            if ($cycle !== null) {
                return $cycle;
            }
        }
        return null;
    }

    /**
     * @param array<string, string[]> $needsByJob
     * @param array<string, bool> $visited
     * @param array<string, bool> $stack
     * @param string[] $path
     * @return string[]|null
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) DFS with cycle bookkeeping
     */
    private static function dfsForCycle(
        string $node,
        array $needsByJob,
        array &$visited,
        array &$stack,
        array &$path
    ): ?array {
        $stack[$node] = true;
        $path[] = $node;

        foreach ($needsByJob[$node] ?? [] as $dep) {
            if (isset($stack[$dep])) {
                // Found a back edge. Slice the path from the first occurrence
                // of $dep and append it again for the closing render.
                $start = array_search($dep, $path, true);
                $cycle = array_slice($path, (int) $start);
                $cycle[] = $dep;
                return $cycle;
            }
            if (isset($visited[$dep])) {
                continue;
            }
            $found = self::dfsForCycle($dep, $needsByJob, $visited, $stack, $path);
            if ($found !== null) {
                return $found;
            }
        }

        array_pop($path);
        unset($stack[$node]);
        $visited[$node] = true;
        return null;
    }

    /**
     * Stable topological sort: among nodes with no dependency relation,
     * preserve the order they appear in `$declarationOrder`. Implementation:
     * iterate over declarationOrder repeatedly, emit any node whose remaining
     * unresolved needs are 0.
     *
     * @param string[] $declarationOrder
     * @param array<string, string[]> $needsByJob
     * @return string[]
     */
    private static function topologicalSort(array $declarationOrder, array $needsByJob): array
    {
        $emitted = [];
        $remaining = $declarationOrder;

        while (!empty($remaining)) {
            $progress = false;
            foreach ($remaining as $i => $node) {
                $unresolved = array_diff($needsByJob[$node] ?? [], $emitted);
                if ($unresolved === []) {
                    $emitted[] = $node;
                    unset($remaining[$i]);
                    $progress = true;
                }
            }
            if (!$progress) {
                // Should be unreachable — findCycle would have caught it.
                break;
            }
            $remaining = array_values($remaining);
        }

        return $emitted;
    }

    /**
     * @param string[] $names
     * @param array<string, string[]> $needsByJob
     * @return array<string, string[]>
     */
    private static function buildReverseEdges(array $names, array $needsByJob): array
    {
        $dependents = [];
        foreach ($names as $name) {
            $dependents[$name] = [];
        }
        foreach ($needsByJob as $job => $needs) {
            foreach ($needs as $dep) {
                $dependents[$dep][] = $job;
            }
        }
        return $dependents;
    }

    /** @return string[] */
    public function getOrderedNames(): array
    {
        return $this->orderedNames;
    }

    /** @return string[] */
    public function getNeedsOf(string $jobName): array
    {
        return $this->needsByJob[$jobName] ?? [];
    }

    /**
     * Transitive dependents of `$jobName`: every job that, directly or
     * indirectly through a chain of `needs`, depends on it. Order is BFS
     * (level by level), unique within the result.
     *
     * @return string[]
     */
    public function descendantsOf(string $jobName): array
    {
        if (!isset($this->dependentsByJob[$jobName])) {
            return [];
        }
        $result = [];
        $queue = $this->dependentsByJob[$jobName];
        while (!empty($queue)) {
            $node = array_shift($queue);
            if (in_array($node, $result, true)) {
                continue;
            }
            $result[] = $node;
            foreach ($this->dependentsByJob[$node] ?? [] as $next) {
                $queue[] = $next;
            }
        }
        return $result;
    }
}
