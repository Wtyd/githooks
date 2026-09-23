<?php

/**
 * Reads the JSON v2 envelope of `githooks flow|flows|job --format=json` from
 * stdin and prints the handful of things worth looking at.
 *
 * Exists so those readings stop being one-off snippets. The same
 * `json.load(sys.stdin)` line was rewritten 31 times in a single session, each
 * time with its own quoting inside the shell — which is also where two of that
 * session's mistakes came from.
 *
 * PHP rather than Python on purpose: it is the project's own language, it runs
 * under the same interpreter the suite uses, and it needs no permission the
 * repository has not already granted.
 *
 * Usage:
 *   githooks flow qa --format=json | php .claude/scripts/flow-json.php
 *   githooks flow qa --format=json | php .claude/scripts/flow-json.php --failed
 *   githooks flow qa --dry-run --format=json | php .claude/scripts/flow-json.php --commands
 *   githooks flow qa --format=json | php .claude/scripts/flow-json.php --field=executionMode
 *
 * Exit codes: 0 when the payload was read, 1 when stdin held no valid JSON or
 * the requested field is absent. It never mirrors the flow's own exit code —
 * measure that on the command itself, not through a pipe.
 */

declare(strict_types=1);

$raw = stream_get_contents(STDIN);
if ($raw === false || trim($raw) === '') {
    fwrite(STDERR, "flow-json: nothing on stdin. Did the command run with --format=json?\n");
    exit(1);
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    fwrite(STDERR, "flow-json: stdin is not valid JSON (" . json_last_error_msg() . ").\n");
    fwrite(STDERR, "The first bytes were: " . substr(trim($raw), 0, 120) . "\n");
    exit(1);
}

$mode = $argv[1] ?? '--summary';
$jobs = is_array($payload['jobs'] ?? null) ? $payload['jobs'] : [];

/**
 * Walks a dotted path (`jobs.0.command`) through the decoded payload.
 *
 * @param array<mixed> $data
 * @return mixed|null
 */
function fieldAt(array $data, string $path)
{
    $cursor = $data;
    foreach (explode('.', $path) as $key) {
        if (!is_array($cursor) || !array_key_exists($key, $cursor)) {
            return null;
        }
        $cursor = $cursor[$key];
    }

    return $cursor;
}

if (strpos($mode, '--field=') === 0) {
    $path = substr($mode, strlen('--field='));
    $value = fieldAt($payload, $path);
    if ($value === null) {
        fwrite(STDERR, "flow-json: no field '$path' in the payload.\n");
        exit(1);
    }
    echo is_scalar($value) ? var_export($value, true) : json_encode($value, JSON_PRETTY_PRINT);
    echo PHP_EOL;
    exit(0);
}

if ($mode === '--commands') {
    foreach ($jobs as $job) {
        printf("%-22s %s\n", (string) $job['name'], (string) ($job['command'] ?? '(none)'));
    }
    exit(0);
}

if ($mode === '--failed') {
    $failed = 0;
    foreach ($jobs as $job) {
        if ($job['success'] || !empty($job['skipped'])) {
            continue;
        }
        $failed++;
        printf(
            "KO %s (%s) exit=%s\n",
            (string) $job['name'],
            (string) ($job['type'] ?? '?'),
            var_export($job['exitCode'] ?? null, true)
        );
        $output = trim((string) ($job['output'] ?? ''));
        if ($output !== '') {
            foreach (array_slice(explode("\n", $output), 0, 12) as $line) {
                echo '   ' . $line . PHP_EOL;
            }
        }
    }
    if ($failed === 0) {
        echo "no failed jobs\n";
    }
    exit(0);
}

// Default: the one-line verdict, plus the names of whatever went wrong.
$passed = (int) ($payload['passed'] ?? 0);
$failedCount = (int) ($payload['failed'] ?? 0);
$skipped = (int) ($payload['skipped'] ?? 0);

printf(
    "%d/%d passed, %d skipped in %s [%s]\n",
    $passed,
    $passed + $failedCount,
    $skipped,
    (string) ($payload['totalTime'] ?? '?'),
    (string) ($payload['executionMode'] ?? '?')
);

foreach ($jobs as $job) {
    if ($job['success'] || !empty($job['skipped'])) {
        continue;
    }
    printf("  KO %s (%s)\n", (string) $job['name'], (string) ($job['type'] ?? '?'));
}

exit(0);
