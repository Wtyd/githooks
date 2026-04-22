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

- **stdout** carries the structured payload only.
- **stderr** carries progress lines (`OK job (Xms) [Y/Z]`, `Done. X/Y completed.`), colours, and any CI annotations.

This means you can pipe cleanly without contamination:

```bash
# Save JSON to disk, let progress show in the terminal
githooks flow qa --format=json > report.json

# Feed JSON to jq, discard progress
githooks flow qa --format=json 2>/dev/null | jq '.jobs[] | select(.success == false)'
```

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
| `skipped` | boolean | `true` when the job was skipped (fast mode with no matching files, `--exclude-jobs`, etc.). |
| `skipReason` | string or null | Free-form reason string when `skipped: true`. |

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
