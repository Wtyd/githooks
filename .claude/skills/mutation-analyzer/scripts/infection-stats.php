<?php

/**
 * Infection report statistics for the GitHooks project.
 *
 * Replaces ad-hoc `grep | awk` / `grep | sed` pipelines that keep tripping
 * the permission allow-list. Reads only the three text artefacts in
 * reports/infection/ — never the HTML.
 *
 * Usage:
 *   php8.4 infection-stats.php --summary
 *       Counts from infection-summary.log + escaped/timed-out totals from
 *       infection.log. Use first to gauge volume.
 *
 *   php8.4 infection-stats.php --by-file [--top=N]
 *       Escapes per src/ file, sorted descending. Default top=25.
 *
 *   php8.4 infection-stats.php --by-mutator [--escaped-only]
 *       Per-mutator.md table parsed to a compact list of mutator → escapes
 *       (and totals). With --escaped-only, only mutators that produced
 *       escapes show up.
 *
 *   php8.4 infection-stats.php --filter=src/Foo.php
 *       Print all escaped/timed-out mutants under the given path with
 *       their log line, mutator and ID. Pair with `Read` on the log offset
 *       to inspect the diff.
 *
 *   php8.4 infection-stats.php --section=timeout
 *       List timed-out mutants (default --section=escaped).
 *
 *   php8.4 infection-stats.php --diff=L1[,L2,...] [--lines=N]
 *       Dump the diff block of each mutant whose log line is given as L<n>
 *       (use the L<n> values from --filter). Default block size is 12 lines.
 *       Avoids `sed -n "${L},$((L+10))p"` loops that trip the permission
 *       allow-list and that are fragile across log layout changes.
 *
 * Flags can combine: `--by-file --top=10`.
 *
 * The reports directory defaults to reports/infection/ relative to the cwd
 * the script is invoked from. Override with --reports=/abs/path.
 */

declare(strict_types=1);

const DEFAULT_REPORTS_DIR = 'reports/infection';

function fail(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

/**
 * @return array<string, string>
 */
function parseArgs(array $argv): array
{
    array_shift($argv);
    $opts = [];
    foreach ($argv as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $opts['help'] = '1';
            continue;
        }
        if (strpos($arg, '--') !== 0) {
            fail("Unknown positional argument: '$arg'. See --help.");
        }
        $body = substr($arg, 2);
        if (strpos($body, '=') !== false) {
            [$k, $v] = explode('=', $body, 2);
            $opts[$k] = $v;
        } else {
            $opts[$body] = '1';
        }
    }
    return $opts;
}

function reportsDir(array $opts): string
{
    $dir = $opts['reports'] ?? DEFAULT_REPORTS_DIR;
    if (!is_dir($dir)) {
        fail("Reports directory '$dir' not found. Pass --reports=<path> or run from the project root.");
    }
    return rtrim($dir, '/');
}

function readSummary(string $dir): array
{
    $path = "$dir/infection-summary.log";
    if (!is_file($path)) {
        fail("infection-summary.log not found at '$path'.");
    }
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (preg_match('/^([A-Za-z][A-Za-z ]*?):\s+(\d+)\s*$/', $line, $m)) {
            $out[trim($m[1])] = (int) $m[2];
        }
    }
    return $out;
}

/**
 * Walk the log, returning mutants grouped by section ('Escaped'|'Timed Out').
 *
 * Each entry: ['line' => int, 'index' => int, 'path' => string, 'mutator' => string, 'id' => string].
 *
 * @return array<string, array<int, array{line:int,index:int,path:string,mutator:string,id:string}>>
 */
function parseLog(string $dir): array
{
    $path = "$dir/infection.log";
    if (!is_file($path)) {
        fail("infection.log not found at '$path'.");
    }

    $section = null;
    $sections = ['Escaped' => [], 'Timed Out' => [], 'Errored' => []];
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        fail("Cannot read '$path'.");
    }

    $lineNo = 0;
    while (($line = fgets($handle)) !== false) {
        $lineNo++;
        $trimmed = rtrim($line, "\r\n");

        if ($trimmed === 'Escaped mutants:') {
            $section = 'Escaped';
            continue;
        }
        if ($trimmed === 'Timed Out mutants:') {
            $section = 'Timed Out';
            continue;
        }
        if ($trimmed === 'Errors mutants:' || $trimmed === 'Errored mutants:') {
            $section = 'Errored';
            continue;
        }
        if ($trimmed === 'Skipped mutants:' || $trimmed === 'Ignored mutants:') {
            $section = null;
            continue;
        }

        if ($section === null) {
            continue;
        }

        // 12) /abs/path/src/Foo.php:42    [M] MutatorName [ID] hash
        if (preg_match('/^(\d+)\)\s+(\S+)\s+\[M\]\s+(\S+)\s+\[ID\]\s+(\S+)/', $trimmed, $m)) {
            $sections[$section][] = [
                'line'    => $lineNo,
                'index'   => (int) $m[1],
                'path'    => $m[2],
                'mutator' => $m[3],
                'id'      => $m[4],
            ];
        }
    }
    fclose($handle);

    return $sections;
}

function relativiseSrc(string $absPath): string
{
    if (preg_match('#/src/(.+?)(:\d+)?$#', $absPath, $m)) {
        return 'src/' . $m[1];
    }
    return $absPath;
}

function commandSummary(array $opts): void
{
    $dir = reportsDir($opts);
    $summary = readSummary($dir);
    $sections = parseLog($dir);

    $total      = $summary['Total']                    ?? 0;
    $killed     = $summary['Killed by Test Framework'] ?? 0;
    $errored    = $summary['Errored']                  ?? 0;
    $syntax     = $summary['Syntax Errors']            ?? 0;
    $escaped    = $summary['Escaped']                  ?? count($sections['Escaped']);
    $timedOut   = $summary['Timed Out']                ?? count($sections['Timed Out']);
    $msi        = $total > 0 ? round(($killed + $timedOut) * 100 / $total, 2) : 0.0;

    printf("Total:       %d%s", $total, PHP_EOL);
    printf("Killed:      %d%s", $killed, PHP_EOL);
    printf("Escaped:     %d%s", $escaped, PHP_EOL);
    printf("Timed Out:   %d%s", $timedOut, PHP_EOL);
    printf("Errored:     %d%s", $errored, PHP_EOL);
    printf("Syntax:      %d%s", $syntax, PHP_EOL);
    printf("MSI:         %s %%%s", number_format($msi, 2), PHP_EOL);
    printf("Auditable:   %d (escaped + timeouts)%s", $escaped + $timedOut, PHP_EOL);
}

function commandByFile(array $opts): void
{
    $dir = reportsDir($opts);
    $sections = parseLog($dir);
    $section = $opts['section'] ?? 'escaped';
    $sectionKey = strtolower($section) === 'timeout' ? 'Timed Out' : 'Escaped';
    $top = isset($opts['top']) ? max(1, (int) $opts['top']) : 25;

    $counts = [];
    foreach ($sections[$sectionKey] ?? [] as $m) {
        $rel = relativiseSrc($m['path']);
        $counts[$rel] = ($counts[$rel] ?? 0) + 1;
    }
    arsort($counts, SORT_NUMERIC);

    printf("=== %s mutants per file (top %d) ===%s", ucfirst(strtolower($section)), $top, PHP_EOL);
    $i = 0;
    foreach ($counts as $file => $n) {
        printf("%5d  %s%s", $n, $file, PHP_EOL);
        if (++$i >= $top) {
            break;
        }
    }
}

function commandByMutator(array $opts): void
{
    $dir = reportsDir($opts);
    $path = "$dir/per-mutator.md";
    if (!is_file($path)) {
        fail("per-mutator.md not found at '$path'.");
    }
    $escapedOnly = isset($opts['escaped-only']);

    $rows = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        if (strpos($line, '|') !== 0) {
            continue;
        }
        $cells = array_map('trim', explode('|', trim($line, '|')));
        // Header rows
        if ($cells[0] === 'Mutator' || strpos($cells[0], '---') === 0) {
            continue;
        }
        if (count($cells) < 7) {
            continue;
        }
        $name     = $cells[0];
        $total    = (int) $cells[1];
        $escaped  = (int) $cells[6];
        if ($escapedOnly && $escaped === 0) {
            continue;
        }
        $rows[] = ['mutator' => $name, 'total' => $total, 'escaped' => $escaped];
    }

    usort($rows, fn(array $a, array $b) => $b['escaped'] <=> $a['escaped'] ?: $b['total'] <=> $a['total']);

    printf("=== Per-mutator (escaped > 0) ===%s", PHP_EOL);
    printf("%5s  %5s  %s%s", 'esc', 'total', 'mutator', PHP_EOL);
    foreach ($rows as $r) {
        if ($escapedOnly && $r['escaped'] === 0) {
            continue;
        }
        printf("%5d  %5d  %s%s", $r['escaped'], $r['total'], $r['mutator'], PHP_EOL);
    }
}

function commandFilter(array $opts): void
{
    $dir = reportsDir($opts);
    $needle = $opts['filter'];
    if ($needle === '' || $needle === '1') {
        fail('--filter requires a value, e.g. --filter=src/Execution/FlowExecutor.php');
    }
    $sections = parseLog($dir);
    $section = $opts['section'] ?? 'escaped';
    $sectionKey = strtolower($section) === 'timeout' ? 'Timed Out' : 'Escaped';

    printf("=== %s mutants matching '%s' (log line, mutator, ID) ===%s", ucfirst(strtolower($section)), $needle, PHP_EOL);
    $count = 0;
    foreach ($sections[$sectionKey] ?? [] as $m) {
        if (strpos($m['path'], $needle) === false) {
            continue;
        }
        printf("L%-5d  %3d) %-50s %-30s %s%s", $m['line'], $m['index'], $m['path'], $m['mutator'], $m['id'], PHP_EOL);
        $count++;
    }
    printf("--- %d mutants ---%s", $count, PHP_EOL);
}

function commandDiff(array $opts): void
{
    $dir = reportsDir($opts);
    $path = "$dir/infection.log";
    if (!is_file($path)) {
        fail("infection.log not found at '$path'.");
    }

    $raw = $opts['diff'];
    if ($raw === '' || $raw === '1') {
        fail('--diff requires one or more line offsets, e.g. --diff=3780,3792 (use the L<n> from --filter).');
    }
    $lines = isset($opts['lines']) ? max(1, (int) $opts['lines']) : 12;

    // Parse offsets: tolerate "3780", "L3780", whitespace, and stray commas.
    $offsets = [];
    foreach (preg_split('/[,\s]+/', $raw) ?: [] as $token) {
        $token = ltrim($token, 'L');
        if ($token === '' || !ctype_digit($token)) {
            continue;
        }
        $offsets[] = (int) $token;
    }
    if ($offsets === []) {
        fail("--diff has no valid numeric offsets after parsing '$raw'.");
    }

    // Sort once so multiple offsets read the file in a single forward pass.
    sort($offsets);
    $needed = [];
    foreach ($offsets as $start) {
        for ($i = $start; $i < $start + $lines; $i++) {
            $needed[$i] = true;
        }
    }

    $buffer = [];
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        fail("Cannot read '$path'.");
    }
    $lineNo = 0;
    while (($line = fgets($handle)) !== false) {
        $lineNo++;
        if (isset($needed[$lineNo])) {
            $buffer[$lineNo] = rtrim($line, "\r\n");
        }
        if ($lineNo > max($offsets) + $lines) {
            break;
        }
    }
    fclose($handle);

    // Emit each requested block in original offset order with a marker so the
    // caller can demarcate them when several are queried at once.
    foreach ($offsets as $start) {
        printf("=== L%d ===%s", $start, PHP_EOL);
        for ($i = $start; $i < $start + $lines; $i++) {
            if (isset($buffer[$i])) {
                echo $buffer[$i] . PHP_EOL;
            }
        }
        echo PHP_EOL;
    }
}

function help(): void
{
    echo <<<HELP
infection-stats.php — Infection report statistics for the GitHooks project.

Modes:
  --summary                          counts + MSI
  --by-file [--top=N] [--section=]   escaped|timeout per file
  --by-mutator [--escaped-only]      per-mutator.md compact list
  --filter=<needle> [--section=]     mutants whose path contains the needle
  --diff=L1[,L2,...] [--lines=N]     dump diff blocks at log offsets (default 12 lines)

Common options:
  --reports=<path>   override reports dir (default: reports/infection/)
  --section=escaped  default for --by-file / --filter; use 'timeout' for timed out
  --help

HELP;
}

$opts = parseArgs($argv);

if (isset($opts['help'])) {
    help();
    exit(0);
}

if (isset($opts['summary'])) {
    commandSummary($opts);
    exit(0);
}
if (isset($opts['by-file'])) {
    commandByFile($opts);
    exit(0);
}
if (isset($opts['by-mutator'])) {
    commandByMutator($opts);
    exit(0);
}
if (isset($opts['filter'])) {
    commandFilter($opts);
    exit(0);
}
if (isset($opts['diff'])) {
    commandDiff($opts);
    exit(0);
}

help();
exit(1);
