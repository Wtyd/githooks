# Output Formats

GitHooks supports six output formats: `text` (default), `json`, `junit`, `codeclimate`, `sarif` and `claude-code`. All are available on the `flow`, `flows` and `job` commands via `--format=FORMAT`.

## Text (default)

Human-readable output with status, time, and error details:

```bash
githooks flow qa
```

```
  parallel_lint - OK. Time: 150ms
  phpcs_src - OK. Time: 890ms
  phpstan_src - KO. Time: 2.34s

  phpstan_src:
    /src/Foo.php:12  Access to undefined property $bar

Results: 2/3 passed in 3.45s
```

## Live streaming for `job` and sequential flows

When you run a single job (`githooks job X`) or a flow with `processes=1`, each tool's output streams in real time. Long-running jobs (phpmd, phpunit with coverage) no longer look frozen — you see the tool's own progress as it happens.

For `flow --processes=1`, a header separator is printed between jobs (like `make` or `docker compose up`):

```
  --- phpstan-src ---
   [OK] No errors
  phpstan-src - OK. Time: 715ms
  --- parallel-lint ---
  Checked 144 files in 0.2 seconds
  parallel-lint - OK. Time: 196ms
```

## Interactive parallel dashboard

When running a flow with `processes > 1` in an interactive terminal (TTY), the text output upgrades to a live dashboard showing queue / running / done states with per-job timers:

```
  ⏳ phpstan-src [0.9s]            ← running, live timer
  ⏳ parallel-lint [0.9s]
  ⏳ phpmd-src [0.9s]
  ⏳ phpcs [0.1s]                  ← just entered a freed slot
  ⏺ phpunit                        ← queued
  ⏺ composer-audit
```

On completion, the dashboard collapses to a clean summary.

**Activation is automatic** via `posix_isatty(STDOUT)`. No flag is needed. In non-TTY environments (CI, redirected stdout, pipes) it falls back to append-only streaming text so logs stay parseable.

!!! tip "`--monitor` is a separate feature"
    `--monitor` adds a **thread-usage report at the end of execution** (peak estimated threads, warning if the budget was exceeded). It is independent of the dashboard — you can combine them (`--monitor` on top of the dashboard) or use it in CI with the plain output.

## stdout / stderr split

For all **structured formats** (`json`, `junit`, `codeclimate`, `sarif`):

- **stdout** carries the structured payload only — never mixed with progress, colours or skip notices.
- **stderr** carries progress lines (`OK job (Xms) [Y/Z]`, `Done. X/Y completed.`), colours, and any CI annotations — **only when a TTY is attached or `--show-progress` is set**.

### Auto-suppress without a TTY

The progress handler detects whether stderr is a TTY. If it is not (pipe, subshell, CI agent, Claude, cron), **no progress is emitted**. stdout stays clean and ready to consume without any redirection:

```bash
# From a script, agent or pipe — stderr is naturally empty
githooks flow qa --format=json | jq '.jobs[] | select(.success == false)'

# Interactive terminal — stderr shows OK/KO while the flow runs
githooks flow qa --format=json > report.json
```

!!! tip "No need for `2>/dev/null`"
    Earlier pre-releases required redirecting stderr to keep consumers happy. From 3.2.0 this is automatic via `stream_isatty(STDERR)` and the idiomatic UNIX pattern used by `git`, `docker` and `npm`.

### Force progress with `--show-progress`

Long-running pipelines in CI can look stuck because stderr is silent by default. Pass `--show-progress` to force progress to be emitted even when stderr is not a TTY:

```bash
# In a CI job that takes several minutes
githooks flow qa --format=json --show-progress --output=report.json
# → stderr: OK phpcs_src (2.1s) [1/6], KO phpstan_src (5.3s) [2/6], …
# → report.json: clean JSON payload
```

`--show-progress` is a dedicated flag: it only affects progress emission, so stdout remains a valid JSON/JUnit/CC/SARIF document. The standard Symfony `-v` / `--verbose` flag is reserved for its original purpose (framework verbosity) and has no effect on progress output.

### Dry-run emits no progress at all

`--dry-run` does not execute any tool, so there is nothing to measure. The progress handler is skipped entirely: stderr stays empty regardless of TTY or `--show-progress`, and stdout contains the structured payload with `totalTime: "0ms"`.

## Writing a report to a file

All four structured formats print to **stdout** by default. Pass `--output=PATH` to write the payload to a file, or use shell redirection — both are equivalent:

```bash
githooks flow qa --format=json       --output=reports/qa.json
githooks flow qa --format=junit      --output=reports/junit.xml
githooks flow qa --format=codeclimate --output=reports/qa-codeclimate.json
githooks flow qa --format=sarif      --output=reports/qa.sarif

# Same result with shell redirection:
githooks flow qa --format=json       > reports/qa.json
githooks flow qa --format=sarif      > reports/qa.sarif
```

Pick the flag form when the surrounding tooling (pipeline DSL, script linter) prefers explicit arguments over shell glue; pick redirection when you are composing with `tee`, filters, or alternate stdout handling.

## JSON v2

Machine-readable output for CI pipelines, scripts, and AI tools:

```bash
githooks flow qa --format=json
```

### Schema

```json
{
  "version": 2,
  "flow": "qa",
  "success": false,
  "totalTime": "15.18s",
  "executionMode": "full",
  "passed": 2,
  "failed": 1,
  "skipped": 0,
  "timeBudget": {
    "warnAfter": 800,
    "failAfter": 1200,
    "totalJobDuration": 1015.2,
    "warned": true,
    "failed": false
  },
  "memoryBudget": {
    "warnAbove": 3000,
    "failAbove": 5000,
    "peakObserved": 3743,
    "peakAtSecond": 12.34,
    "peakAttribution": [{ "name": "phpstan_src", "value": 3500 }],
    "warned": true,
    "failed": false
  },
  "stats": {
    "cores": {
      "limit": 8,
      "flowPeak": { "value": 8, "atSecond": 0.01, "jobsInFlight": ["phpstan_src", "phpcs", "phpunit"] }
    },
    "memory": {
      "flowPeak": { "value": 3743, "atSecond": 12.34, "jobsInFlight": [{ "name": "phpstan_src", "value": 3500 }] }
    }
  },
  "warnings": [],
  "deprecations": [],
  "jobs": [
    {
      "name": "phpstan_src",
      "type": "phpstan",
      "success": false,
      "time": "2.34s",
      "duration": 2.34,
      "exitCode": 1,
      "output": "src/Foo.php:12  Access to undefined property $bar",
      "fixApplied": false,
      "command": "vendor/bin/phpstan analyse -c qa/phpstan.neon --no-progress src",
      "paths": ["src"],
      "skipped": false,
      "skipReason": null,
      "threshold": {
        "warnAfter": 120,
        "failAfter": 180,
        "warned": false,
        "failed": false,
        "reason": null
      },
      "memoryReserved": null,
      "memoryPeak": 3500,
      "memoryThreshold": null,
      "killedReason": null
    }
  ]
}
```

### Top-level fields

| Field | Type | Description |
|---|---|---|
| `version` | integer | Schema version — currently `2`. Bumped on breaking changes. |
| `flow` | string | Flow name (or job name when called from `githooks job`). |
| `success` | boolean | `true` if **all** non-skipped jobs passed AND no flow-level `fail-after` / `fail-above` was crossed. |
| `totalTime` | string | Human-readable wall-clock time. `"0ms"` under `--dry-run`. |
| `executionMode` | string | `"full"`, `"fast"`, `"fast-branch"`, `"fast-dirty"` or `"files"`. Reflects the actual mode used. |
| `passed` / `failed` / `skipped` | integer | Counters matching the entries in `jobs[]`. |
| `timeBudget` | object or null | Flow-level time budget state. `null` when not configured. See [Time budget block](#time-budget-block). |
| `memoryBudget` | object or null | Flow-level memory budget state. `null` when not configured. See [Memory budget block](#memory-budget-block). |
| `stats` | object or null | RSS sampling + cores attribution. `null` when `stats: false` (default). See [Stats block](#stats-block). |
| `inputFiles` | object | Present only in files mode (`--files` / `--files-from`). See [Input files block](#input-files-block). |
| `flows` | array | Present only in multi-flow runs. List of normal flows actually executed after meta-flow expansion. |
| `effectiveOptions` | object | Always present in `flow` / `flows` / `job` runs. Each option's `value` and resolved `source`. See [Effective options and conditions header](#effective-options-and-conditions-header). |
| `warnings` | string[] | Always present (empty when no warnings). Validation warnings emitted on stderr during the run. |
| `deprecations` | object[] | Always present (empty when none). Each entry: `{job, oldKey, newKey, removalVersion, kind}`. See [v3.3 deprecations](../migration/v33-deprecations.md). |

### Per-job fields

| Field | Type | Description |
|---|---|---|
| `name` | string | Job name as configured. |
| `type` | string | Job type (`phpstan`, `phpcs`, `custom`, …). |
| `success` | boolean | `true` if the job passed AND no per-job `fail-after` / `fail-above` was crossed. |
| `time` | string | Human-readable execution time. |
| `duration` | float | Execution time in seconds (raw — useful for sorting / comparisons). |
| `exitCode` | integer or null | Underlying tool exit code. `null` for skipped jobs. |
| `output` | string | Captured stdout/stderr of the tool, ANSI escapes stripped. |
| `fixApplied` | boolean | `true` when the job modified files (fix jobs in non dry-run). |
| `command` | string | Shell command that was executed (always present; useful under `--dry-run`). |
| `paths` | array | Paths analysed (after fast / fast-branch / files filtering). |
| `skipped` | boolean | `true` when the job was skipped (fast mode with no matching files, `--exclude-jobs`, fail-fast cancellation, or `fail-above` killing the queue). |
| `skipReason` | string or null | Free-form reason string when `skipped: true`. |
| `threshold` | object or null | Per-job time threshold state. `null` when no `warn-after` / `fail-after` configured. See [Per-job threshold block](#per-job-threshold-block). |
| `memoryReserved` | integer or null | MB reserved by the 2D allocator for this job. `null` when no short-form `memory:` was declared. |
| `memoryPeak` | integer or null | Peak RSS observed (MB). `null` when the sampler did not run (no stats / Windows). |
| `memoryThreshold` | object or null | Per-job memory threshold state. `null` when no `memory` threshold configured. See [Per-job memory threshold block](#per-job-memory-threshold-block). |
| `killedReason` | string or null | Set when the job was killed mid-run by a flow-level guard (e.g. `"flow memory-budget exceeded"`). `null` otherwise. |
| `inputFiles` | object | Present on accelerable jobs in files mode. The slice of input files that matched this job's `paths`. |

### Time budget block

`timeBudget` (root) carries the flow-level state under the explicit-null
pattern: present as an object with the same shape always when configured,
`null` when no `time-budget` was declared (or `--no-time-budget` was set):

```json
"timeBudget": {
  "warnAfter": 800,
  "failAfter": 1200,
  "totalJobDuration": 1015.2,
  "warned": true,
  "failed": false
}
```

`totalJobDuration` is the post-hoc sum of executed-job durations in
seconds. `warned` / `failed` flip `true` when their respective threshold
is crossed. A flow with `failed: true` exits `1` even when every job's
own `success` is `true`.

### Per-job threshold block

`threshold` (per job) carries per-job warn-after / fail-after state. `null`
when not configured:

```json
"threshold": {
  "warnAfter": 120,
  "failAfter": 180,
  "warned": false,
  "failed": true,
  "reason": "execution exceeded fail-after (181.2s)"
}
```

`reason` is a string when `warned` or `failed` is `true`, `null` when both
are `false`. A job with `failed: true` flips its top-level `success` to
`false` even when the underlying tool exited `0`.

### Memory budget block

`memoryBudget` (root) carries the flow-level RSS guard. `null` when not
configured:

```json
"memoryBudget": {
  "warnAbove": 3000,
  "failAbove": 5000,
  "peakObserved": 3743,
  "peakAtSecond": 12.34,
  "peakAttribution": [{ "name": "phpstan_src", "value": 3500 }],
  "warned": true,
  "failed": false
}
```

`peakAttribution` is the list of jobs in flight at the peak instant with
their individual contribution (MB). When `failed: true`, the runtime kills
jobs in flight via `process->stop(0)` and skips queued ones with reason
`"flow memory-budget exceeded"`.

### Per-job memory threshold block

`memoryThreshold` (per job): `null` when not configured. Same shape as
`threshold` but with `warnAbove` / `failAbove` (MB):

```json
"memoryThreshold": {
  "warnAbove": 1500,
  "failAbove": 2000,
  "warned": false,
  "failed": true,
  "reason": "peak 2150 MB exceeded fail-above (2000 MB)"
}
```

### Stats block

`stats` (root) is emitted only when `stats: true` (config) or `--stats`
(CLI). The `cores` sub-block is always present when active (deterministic
from the schedule); the `memory` sub-block is present only when the RSS
sampler produced data (Linux/macOS — Windows degrades gracefully):

```json
"stats": {
  "cores": {
    "limit": 8,
    "flowPeak": {
      "value": 8,
      "atSecond": 0.01,
      "jobsInFlight": ["phpstan_src", "phpcs", "phpunit"]
    }
  },
  "memory": {
    "flowPeak": {
      "value": 3743,
      "atSecond": 12.34,
      "jobsInFlight": [{ "name": "phpstan_src", "value": 3500 }]
    }
  }
}
```

### Input files block

`inputFiles` (root) is emitted only in files mode (`--files`,
`--files-from`). Per-job `inputFiles` shows the slice that matched each
accelerable job's `paths`:

```json
"inputFiles": {
  "source": "files-from",
  "sourcePath": "/tmp/changed.txt",
  "totalProvided": 142,
  "totalValid": 138,
  "invalid": ["does/not/exist.php"],
  "excludedPatterns": ["**/Generated/**"],
  "excluded": ["src/Generated/Foo.php"],
  "totalAfterExclude": 137
}
```

`source` is `"files"` or `"files-from"`. `sourcePath` is the manifest path
when `--files-from`, `null` for inline `--files`. `excludedPatterns` /
`excluded` / `totalAfterExclude` are present only when
`--exclude-pattern` was used. See [How-To: --files / --files-from](files-flag.md).

### Fail-fast and the `jobs[]` array

When `--fail-fast` cancels the remaining jobs after a failure, the JSON payload still contains **every job in the plan**. The ones that were not executed appear with:

```json
{
  "name": "phpunit_tests",
  "type": "phpunit",
  "success": true,
  "skipped": true,
  "skipReason": "skipped by fail-fast",
  "exitCode": null,
  "time": "0ms"
}
```

This keeps structured consumers honest: the array size equals the declared plan size, and the `skipped` counter at the top level reflects both fast-mode skips and fail-fast cancellations.

## JUnit

JUnit XML compatible with GitHub Actions, GitLab CI, Jenkins and other test reporting tools:

```bash
githooks flow qa --format=junit > junit.xml
```

Skipped jobs emit `<skipped>` elements:

```xml
<testcase name="phpstan_src" time="0.000" classname="phpstan">
  <skipped message="No staged files match the configured paths"/>
</testcase>
```

Use with test reporting actions:

```yaml
# GitHub Actions
- run: vendor/bin/githooks flow qa --format=junit > junit.xml
- uses: mikepenz/action-junit-report@v4
  if: always()
  with:
    report_paths: junit.xml
```

## Code Climate

GitLab-compatible Code Quality report. Emits a JSON array where each entry is a CodeIssue:

```bash
githooks flow qa --format=codeclimate                                # prints to stdout
githooks flow qa --format=codeclimate --output=reports/quality.json  # writes a file
```

Each issue's `location.path` is **relative to the current working directory**. Absolute paths emitted by tool parsers (phpcs, for instance) are normalised to the workspace root so the report is portable and links correctly in the GitLab UI:

```json
{
  "description": "...",
  "location": { "path": "src/errors/SyntaxError.php", "lines": { "begin": 3 } }
}
```

Paths outside the CWD are left untouched.

Integrate directly with GitLab CI — the `--output` path must match the `codequality` artifact declared in the job:

```yaml
qa:
  script: vendor/bin/githooks flow qa --format=codeclimate --output=gl-code-quality-report.json
  artifacts:
    reports:
      codequality: gl-code-quality-report.json
```

## SARIF

SARIF 2.1.0 report consumable by GitHub Code Scanning, Azure DevOps, and other static-analysis tools:

```bash
githooks flow qa --format=sarif                              # prints to stdout
githooks flow qa --format=sarif --output=reports/qa.sarif    # writes a file
```

`artifactLocation.uri` is **relative to the current working directory**, matching the SARIF convention expected by Code Scanning. Absolute paths from tool parsers are normalised; paths outside the CWD are preserved as-is.

Upload to GitHub Code Scanning — the `--output` path must match the `sarif_file` argument of the upload step:

```yaml
- run: vendor/bin/githooks flow qa --format=sarif --output=githooks-results.sarif
- uses: github/codeql-action/upload-sarif@v3
  if: always()
  with:
    sarif_file: githooks-results.sarif
```

## Claude Code stop-hook

`--format=claude-code` emits the Claude Code AI agent *stop-hook* protocol: empty stdout
and exit 0 on success, a `{"decision":"block","reason":…}` JSON on failure (also exit 0, by
protocol). It replaces the per-repo bash wrapper that AI integrations used to need.

```bash
githooks flow qa --fast-dirty --format=claude-code
```

See the dedicated guide: [AI Agent Hooks (Claude Code)](ai-hooks.md).

## Single job output

The `--format` and `--output` flags work identically with the `job` command:

```bash
githooks job phpstan_src --format=json                                   # JSON v2 to stdout
githooks job phpcs_src   --format=junit                                  # JUnit to stdout
githooks job phpstan_src --format=sarif  --output=reports/phpstan.sarif  # SARIF to a file
```

## Dry-run

Combine `--dry-run` with any format to see what commands would run:

```bash
githooks flow qa --dry-run                 # text
githooks flow qa --dry-run --format=json   # JSON with .command per job
```

In dry-run the `command` field per job is the exact shell command that would have executed, so it can be reused by other tools or documented elsewhere.

## Effective options and conditions header

Every `flow`, `flows` and `job` run prints a **conditions header** at the start so the operator sees with which `processes`, `fail-fast`, `mode`, budgets, allocator and stats the plan is running, and where each value comes from. One row per option, aligned, every row carries its `(source)` parenthesis — `(default)` included — so the column stays aligned and the audit trail is complete:

```
Settings:
  processes     = 4    (cli)
  fail-fast     = true (flows.ci-pack.options)
  mode          = full (default)
  time-budget   = none (default)
  memory-budget = none (default)
  allocator     = fifo (default)
  stats         = false (default)
Flows: qa, lint
```

- The header writes to **stdout** in text mode (default) and to **stderr** when a structured format is combined with `--show-progress` (so stdout payloads stay clean for piping).
- The optional `Flows:` line appears in declarative, ad-hoc and mixed multi-flow runs (omitted in `flow X` and `flows X` single-flow degenerate).
- `(default)` is a meaningful signal — "this fell through, nothing overrode it" — and is the exact answer the operator wants when behaviour is surprising.
- The same information is exposed in JSON v2 as the `effectiveOptions` root block (always present in `flow` / `flows` / `job` runs, additive to v2):

  ```json
  {
    "version": 2,
    "flow": "ci-pack",
    "flows": ["qa", "lint"],
    "effectiveOptions": {
      "processes":     { "value": 4,    "source": "flows.ci-pack.options" },
      "failFast":      { "value": true, "source": "flows.ci-pack.options" },
      "executionMode": { "value": "full", "source": "default" }
    }
  }
  ```

  `source` is one of `cli`, `flows.<X>.options`, `flows.<alias>.options`, `flows.options`, or `default`. In ad-hoc and mixed multi-flow runs the per-flow / per-alias options are deliberately ignored, so `source` is restricted to `{cli, flows.options, default}`.

The `flows[]` root array (also additive) lists the normal flows actually executed after meta-flow expansion when relevant. Existing v2 consumers that ignore both fields keep working unchanged.
