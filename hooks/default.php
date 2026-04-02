#!/bin/php
<?php

$backFiles = shell_exec('git diff --cached --name-only --diff-filter=ACM | grep ".php$\\|^composer.json$\\|^composer.lock$"');

if (!empty($backFiles)) {
    passthru('php vendor/bin/githooks tool all', $exit);

    if ($exit === 0) {
        // Re-stage files that may have been modified by auto-fixing tools (phpcbf)
        exec('git diff --cached --name-only --diff-filter=d', $stagedFiles);
        if (!empty($stagedFiles)) {
            $escaped = array_map('escapeshellarg', $stagedFiles);
            exec('git add ' . implode(' ', $escaped));
        }
    }

    exit($exit);
}
