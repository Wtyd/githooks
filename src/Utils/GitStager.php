<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Utils;

class GitStager implements GitStagerInterface
{
    /**
     * Re-stages files that are already in the index (cached) to capture modifications
     * made by auto-fixing tools (e.g. phpcbf).
     *
     * @return void
     */
    public function stageTrackedFiles(): void
    {
        $stagedFiles = [];
        exec('git diff --cached --name-only --diff-filter=d', $stagedFiles);

        if (!empty($stagedFiles)) {
            $escaped = array_map('escapeshellarg', $stagedFiles);
            exec('git add ' . implode(' ', $escaped));
        }
    }
}
