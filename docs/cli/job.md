# githooks job

Run a single job in isolation. Useful for debugging job configuration or running a specific check.

## Synopsis

```
githooks job <name> [options]
```

## Options

| Option | Description |
|---|---|
| `--fail-fast` | Forwarded to the flow-preparer infrastructure; for a single-job run it only affects re-stage behaviour on fix jobs. See [Options: Fail-fast and ignoreErrorsOnExit](../configuration/options.md#fail-fast-and-ignoreerrorsonexit). |
| `--ignore-errors-on-exit` | Return exit code 0 even if the job fails (useful for advisory checks). See [Options: Fail-fast and ignoreErrorsOnExit](../configuration/options.md#fail-fast-and-ignoreerrorsonexit). |
| `--dry-run` | Show command without executing. |
| `--format=FORMAT` | Output format: `text` (default), `json`, `junit`, `codeclimate`, `sarif`. See [How-To: Output Formats](../how-to/output-formats.md). |
| `--output=PATH` | Write the structured payload to `PATH` (only for `json` / `junit` / `codeclimate` / `sarif`). Default: stdout. See [Writing a report to a file](../how-to/output-formats.md#writing-a-report-to-a-file). |
| `--fast` | Fast mode — analyze only staged files. See [Execution Modes](../execution-modes.md). |
| `--fast-branch` | Fast-branch mode — analyze branch diff files. The branch name comes from the [`main-branch` option](../configuration/options.md#available-options); see [Execution Modes](../execution-modes.md) and [Fast-branch fallback](../execution-modes.md#fast-branch-fallback). |
| `--no-ci` | Disable auto-detection of CI annotations. See [CI Annotations](../how-to/ci-cd.md#ci-annotations). |
| `--show-progress` | Force progress emission on stderr even when not a TTY. Useful in CI with `--format=json\|junit\|sarif\|codeclimate` to make long pipelines visible in the runner log. |
| `--config=PATH` | Path to configuration file. |
| `-- ARGS...` | Extra arguments passed to the tool. Place after `--` separator. |

## Examples

```bash
githooks job phpstan_src                  # Run a single job
githooks job phpstan_src --dry-run        # Show command without running
githooks job phpunit_all --format=json    # JSON v2 output to stdout
githooks job phpstan_src --format=sarif   # SARIF 2.1.0 to stdout
githooks job phpstan_src --format=sarif --output=reports/phpstan.sarif   # SARIF to a file
githooks job phpcs_src --fast             # Only staged files
githooks job phpunit_all -- --filter=testFoo          # Pass extra args to the tool
githooks job phpstan_src -- --memory-limit=2G         # Override memory limit
```

## Structured output

`job` accepts the same output formats as `flow` — `json`, `junit`, `codeclimate`, `sarif`. Payload on **stdout**; progress on **stderr only when attached to a TTY** (otherwise silent). Use `--show-progress` to force progress in scripts or CI; `--dry-run` never emits progress. Passing extra args after `--` (e.g. `-- --filter=...`) is preserved end-to-end in the generated command.

See [How-To: Output Formats](../how-to/output-formats.md) for the full schema.

## Exit codes

| Code | Meaning |
|---|---|
| `0` | Job passed. |
| `1` | Job failed. |

## See also

- [Configuration: Jobs](../configuration/jobs.md)
- [`githooks flow`](flow.md) — run a group of jobs.
