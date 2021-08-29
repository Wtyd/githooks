#!/bin/php
<?php

$backFiles = shell_exec('git diff --cached --name-only --diff-filter=ACM | grep ".php$\\|^composer.json$\\|^composer.lock$"');

if (!empty($backFiles)) {
    passthru('php vendor/bin/githooks tool all', $exit);

    exit($exit);
}
