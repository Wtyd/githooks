<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution\Process;

/**
 * The one BFS over a process tree, shared by every ProcessTree
 * implementation and both RSS samplers. Depth-capped and cycle-safe: a PID
 * listed twice (or listing one of its own ancestors) is visited once.
 */
final class ProcessTreeWalk
{
    public const MAX_TREE_DEPTH = 16;

    /**
     * Descendants of $rootPid in BFS order (parents before children), root
     * excluded. Children of a node at depth MAX_TREE_DEPTH are not expanded.
     *
     * @param callable(int): int[] $childrenOf Direct children of a PID.
     * @return int[]
     */
    public static function descendants(int $rootPid, callable $childrenOf): array
    {
        $found = [];
        $queue = [[$rootPid, 0]];
        $visited = [$rootPid => true];

        while (!empty($queue)) {
            [$pid, $depth] = array_shift($queue);
            if ($depth >= self::MAX_TREE_DEPTH) {
                continue;
            }
            foreach ($childrenOf($pid) as $childPid) {
                if (isset($visited[$childPid])) {
                    continue;
                }
                $visited[$childPid] = true;
                $found[] = $childPid;
                $queue[] = [$childPid, $depth + 1];
            }
        }

        return $found;
    }
}
