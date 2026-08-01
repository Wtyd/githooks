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
        $this->add($this->stagedFiles());
    }

    /**
     * @return array<string, string>
     */
    public function snapshotStagedFiles(): array
    {
        $snapshot = [];
        foreach ($this->stagedFiles() as $file) {
            $snapshot[$file] = $this->fingerprint($file);
        }

        return $snapshot;
    }

    /**
     * A fixer only ever rewrites the files it was pointed at, so re-staging the
     * whole index would also sweep in edits the author deliberately left
     * unstaged. Comparing against the pre-run fingerprint keeps the re-stage to
     * what actually changed while the jobs ran.
     *
     * Note the inherent limit of `git add`: a file the fixer did rewrite is
     * staged whole, including any unstaged edit it already carried.
     *
     * @param array<string, string> $snapshot
     * @return void
     */
    public function stageModifiedSince(array $snapshot): void
    {
        $modified = [];
        foreach ($this->stagedFiles() as $file) {
            // Absent from the snapshot means the file entered the index during
            // the run; a changed fingerprint means a job rewrote it.
            if (!array_key_exists($file, $snapshot) || $snapshot[$file] !== $this->fingerprint($file)) {
                $modified[] = $file;
            }
        }

        $this->add($modified);
    }

    /**
     * Paths currently in the index, deletions excluded (`git add` on a path the
     * tool removed would stage the deletion).
     *
     * @return string[]
     */
    private function stagedFiles(): array
    {
        $files = [];
        exec('git diff --cached --name-only --diff-filter=d', $files);

        return $files;
    }

    /**
     * Content hash of the working-tree copy. A path with no file on disk gets a
     * stable marker instead, so it compares equal to itself across calls.
     */
    private function fingerprint(string $file): string
    {
        if (!is_file($file)) {
            return '';
        }

        return (string) md5_file($file);
    }

    /**
     * @param string[] $files
     */
    private function add(array $files): void
    {
        if (empty($files)) {
            return;
        }

        $escaped = array_map('escapeshellarg', $files);
        exec('git add ' . implode(' ', $escaped));
    }
}
