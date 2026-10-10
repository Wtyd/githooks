<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Utils;

interface GitStagerInterface
{
    /**
     * Content fingerprint of every file currently in the index, keyed by path.
     * Taken before the jobs run so the changes they make can be told apart from
     * the ones already in the working tree.
     *
     * @return array<string, string>
     */
    public function snapshotStagedFiles(): array;

    /**
     * Re-stage only the staged files whose working-tree content differs from
     * the given snapshot — i.e. those a fixer rewrote during the run.
     *
     * @param array<string, string> $snapshot as returned by {@see snapshotStagedFiles()}
     * @return void
     */
    public function stageModifiedSince(array $snapshot): void;
}
