# githooks job

Run a single job in isolation. Useful for debugging job configuration or running a specific check.

## Synopsis

```
githooks job <name> [options]
```

## Options

Most flags below are CLI overrides for keys declared under `flows.options` or in the job definition. The table here describes the CLI form; the linked sections in [Configuration: Options](../configuration/options.md) and [Configuration: Jobs](../configuration/jobs.md) carry defaults, types and validation semantics.

| Option | Description |
|---|---|
| `--fail-fast` | Forwarded to the flow-preparer infrastructure; for a single-job run it only affects re-stage behaviour on fix jobs. See [Options: Fail-fast and ignore-errors-on-exit](../configuration/options.md#fail-fast-and-ignore-errors-on-exit). |
| `--ignore-errors-on-exit` | Return exit code 0 even if the job fails (useful for advisory checks). See [Options: Fail-fast and ignore-errors-on-exit](../configuration/options.md#fail-fast-and-ignore-errors-on-exit). |
| `--dry-run` | Show command without executing. |
| `--format=FORMAT` | Output format: `text` (default), `json`, `junit`, `codeclimate`, `sarif`. See [How-To: Output Formats](../how-to/output-formats.md). |
| `--output=PATH` | Write the structured payload to `PATH` (only for `json` / `junit` / `codeclimate` / `sarif`). Default: stdout. See [Writing a report to a file](../how-to/output-formats.md#writing-a-report-to-a-file). |
| `--report-FORMAT=PATH` | Emit an extra report file alongside `--format`. One flag per format: `--report-json`, `--report-junit`, `--report-sarif`, `--report-codeclimate`. CLI overrides config entry by entry. See [Multi-report](../configuration/options.md#multi-report). |
| `--no-reports` | Ignore the `reports` section from config (CLI `--report-*` flags still apply). |
| `--fast` | Fast mode — analyze only staged files. See [Execution Modes](../execution-modes.md). |
| `--fast-branch` | Fast-branch mode — analyze branch diff files. The branch name comes from the [`main-branch` option](../configuration/options.md#available-options); see [Execution Modes](../execution-modes.md) and [Fast-branch fallback](../execution-modes.md#fast-branch-fallback). |
| `--fast-dirty` | Fast-dirty mode — analyze the unified working tree (tracked files modified vs `HEAD`, staged or unstaged, ∪ non-ignored untracked). Clean tree → job skipped, exit 0 (no fallback to `full`). Mutually exclusive with `--fast`, `--fast-branch`, `--files`, `--files-from`. See [Fast-dirty mode](../execution-modes.md#fast-dirty-mode-fast-dirty). |
| `--files=a,b,c` | Files mode — explicit list (CSV). Mutually exclusive with `--files-from`, `--fast`, `--fast-branch`, `--fast-dirty`. See [How-To: --files / --files-from](../how-to/files-flag.md). |
| `--files-from=PATH` | Files mode — read paths from a manifest file (one per line). |
| `--exclude-pattern=glob1,glob2` | Drop input paths that match any glob. Requires `--files` or `--files-from`. |
| `--no-ci` | Disable auto-detection of CI annotations. See [CI Annotations](../how-to/ci-cd.md#ci-annotations). |
| `--warn-after=N` / `--fail-after=N` | Override per-job time thresholds (seconds). See [Jobs: Per-job time threshold](../configuration/jobs.md#per-job-time-threshold-warn-after-fail-after). |
| `--no-time-budget` | Disable the per-job time threshold for this run. |
| `--memory-warn-above=N` / `--memory-fail-above=N` | Override per-job RSS thresholds (MB). See [Jobs: Per-job memory threshold](../configuration/jobs.md#per-job-memory-threshold-memory). |
| `--no-memory-budget` | Disable the per-job memory threshold for this run. |
| `--stats` | Activate RSS sampling and the summary table after the run. See [Options: Stats](../configuration/options.md#stats-stats). |
| `--show-progress` | Force progress emission on stderr even when not a TTY. Useful in CI with `--format=json\|junit\|sarif\|codeclimate` to make long pipelines visible in the runner log. |
| `--diag` | Print a runtime diagnostics block (githooks version, platform, CPU/cgroup limit, available memory, load averages, start timestamp) before the run. Opt-in locally; **auto-on in CI**. See [Runtime diagnostics](../how-to/output-formats.md#runtime-diagnostics-and-absolute-timestamps). |
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
githooks job phpcs_src --fast-dirty       # Working tree diff vs HEAD ∪ untracked
githooks job phpstan_src --files=src/User.php   # Files mode: single explicit file (IDE on-save)
githooks job phpstan_src --files-from=changed.txt   # Files mode: read paths from manifest
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

## Conditions header

`job` prints the same one-line **conditions header** as `flow` / `flows`, with `processes`, `fail-fast`, and `mode` resolved through the `flows.options > default` cascade (per-flow options do not apply to standalone jobs). Its machine-readable counterpart is the [`effectiveOptions`](../how-to/output-formats.md#effective-options-and-conditions-header) block in JSON v2.

## See also

- [Configuration: Jobs](../configuration/jobs.md)
- [`githooks flow`](flow.md) — run a group of jobs.
- [`githooks flows`](flows.md) — run several flows or a meta-flow in a single plan.
