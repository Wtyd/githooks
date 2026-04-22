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

## Interactive dashboard

When running with `processes > 1` in an interactive terminal (TTY), the text output upgrades to an interactive dashboard showing queue / running / done states in real time. It falls back to streaming text output in non-TTY environments (CI, piped runs) so the output stays clean for log parsers.

Force the streaming text output in a TTY with:

```bash
githooks flow qa --monitor    # adds a thread-budget summary at the end
```

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
githooks flow qa --format=codeclimate             # writes gl-code-quality-report.json
githooks flow qa --format=codeclimate --stdout    # prints to stdout
githooks flow qa --format=codeclimate --output=reports/quality.json
```

Integrate directly with GitLab CI:

```yaml
qa:
  script: vendor/bin/githooks flow qa --format=codeclimate
  artifacts:
    reports:
      codequality: gl-code-quality-report.json
```

## SARIF

SARIF 2.1.0 report consumable by GitHub Code Scanning, Azure DevOps, and other static-analysis tools:

```bash
githooks flow qa --format=sarif                   # writes githooks-results.sarif
githooks flow qa --format=sarif --stdout
githooks flow qa --format=sarif --output=reports/qa.sarif
```

Upload to GitHub Code Scanning:

```yaml
- run: vendor/bin/githooks flow qa --format=sarif
- uses: github/codeql-action/upload-sarif@v3
  if: always()
  with:
    sarif_file: githooks-results.sarif
```

## Single job output

The `--format` flag, `--output` and `--stdout` all work with the `job` command too:

```bash
githooks job phpstan_src --format=json
githooks job phpcs_src --format=junit
githooks job phpstan_src --format=sarif --stdout
```

## Dry-run

Combine `--dry-run` with any format to see what commands would run:

```bash
githooks flow qa --dry-run                 # text
githooks flow qa --dry-run --format=json   # JSON with .command per job
```

In dry-run the `command` field per job is the exact shell command that would have executed, so it can be reused by other tools or documented elsewhere.
