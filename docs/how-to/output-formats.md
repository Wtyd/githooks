# Output Formats

GitHooks supports five output formats: `text` (default), `json`, `junit`, `codeclimate` and `sarif`. All are available on both `flow` and `job` commands via `--format=FORMAT`.

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
  --- Phpstan Src ---
   [OK] No errors
  Phpstan Src - OK. Time: 715ms
  --- Parallel-lint ---
  Checked 144 files in 0.2 seconds
  Parallel-lint - OK. Time: 196ms
```

## Interactive parallel dashboard

When running a flow with `processes > 1` in an interactive terminal (TTY), the text output upgrades to a live dashboard showing queue / running / done states with per-job timers:

```
  ⏳ Phpstan Src [0.9s]            ← running, live timer
  ⏳ Parallel-lint [0.9s]
  ⏳ Phpmd Src [0.9s]
  ⏳ Phpcs [0.1s]                  ← just entered a freed slot
  ⏺ Phpunit                        ← queued
  ⏺ Composer Audit
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
  "totalTime": "3.45s",
  "executionMode": "full",
  "passed": 2,
  "failed": 1,
  "skipped": 0,
  "jobs": [
    {
      "name": "phpstan_src",
      "type": "phpstan",
      "success": false,
      "time": "2.34s",
      "exitCode": 1,
      "output": "src/Foo.php:12  Access to undefined property $bar",
      "fixApplied": false,
      "command": "vendor/bin/phpstan analyse -c qa/phpstan.neon --no-progress src",
      "paths": ["src"],
      "skipped": false,
      "skipReason": null
    }
  ]
}
```

### Top-level fields

| Field | Type | Description |
|---|---|---|
| `version` | integer | Schema version — currently `2`. Bumped on breaking changes. |
| `flow` | string | Flow name (or job name when called from `githooks job`). |
| `success` | boolean | `true` if **all** non-skipped jobs passed. |
| `totalTime` | string | Human-readable wall-clock time. `"0ms"` under `--dry-run`. |
| `executionMode` | string | `"full"`, `"fast"` or `"fast-branch"`. Reflects the actual `--fast` / `--fast-branch` flag used. |
| `passed` / `failed` / `skipped` | integer | Counters matching the entries in `jobs[]`. |

### Per-job fields

| Field | Type | Description |
|---|---|---|
| `name` | string | Job name as configured. |
| `type` | string | Job type (`phpstan`, `phpcs`, `custom`, …). |
| `success` | boolean | `true` if the job passed. |
| `time` | string | Human-readable execution time. |
| `exitCode` | integer | Underlying tool exit code. |
| `output` | string | Captured stdout/stderr of the tool. |
| `fixApplied` | boolean | `true` when the job modified files (fix jobs in non dry-run). |
| `command` | string | Shell command that was executed (always present; useful under `--dry-run`). |
| `paths` | array | Paths analysed (after fast / fast-branch filtering). |
| `skipped` | boolean | `true` when the job was skipped (fast mode with no matching files, `--exclude-jobs`, or a fail-fast trigger in an earlier job). |
| `skipReason` | string or null | Free-form reason string when `skipped: true`. |

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
