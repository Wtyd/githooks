# githooks flow

Run a flow by name. A flow executes its configured jobs with the flow's options.

## Synopsis

```
githooks flow <name> [options]
```

## Options

| Option | Description |
|---|---|
| `--fail-fast` | Stop on first job failure. Overrides config value. See [Options: Fail-fast and ignoreErrorsOnExit](../configuration/options.md#fail-fast-and-ignoreerrorsonexit). |
| `--processes=N` | Number of parallel processes. Overrides config value. `N` is a thread budget that is distributed across internally-parallel tools — see [Options: Thread budget](../configuration/options.md#thread-budget). |
| `--exclude-jobs=a,b` | Comma-separated list of jobs to skip. |
| `--only-jobs=a,b` | Comma-separated list of jobs to run (others skipped). Cannot combine with `--exclude-jobs`. |
| `--dry-run` | Show commands without executing. Works with all `--format` options. |
| `--format=FORMAT` | Output format: `text` (default), `json`, `junit`, `codeclimate`, `sarif`. See [How-To: Output Formats](../how-to/output-formats.md). |
| `--output=PATH` | Write the structured payload to `PATH` (only for `json` / `junit` / `codeclimate` / `sarif`). Default: stdout. See [Writing a report to a file](../how-to/output-formats.md#writing-a-report-to-a-file). |
| `--report-FORMAT=PATH` | Emit an extra report file alongside whatever `--format` writes. One flag per format: `--report-json`, `--report-junit`, `--report-sarif`, `--report-codeclimate`. CLI overrides config entry by entry. See [Multi-report](../configuration/options.md#multi-report). |
| `--no-reports` | Ignore the `reports` section from config (CLI `--report-*` flags still apply). PHPUnit `--no-coverage` style. |
| `--fast` | Fast mode — accelerable jobs analyze only staged files. See [Execution Modes](../execution-modes.md). |
| `--fast-branch` | Fast-branch mode — analyze files that differ from the main branch. The branch name comes from the [`main-branch` option](../configuration/options.md#available-options); see [Execution Modes](../execution-modes.md) and [Fast-branch fallback](../execution-modes.md#fast-branch-fallback). |
| `--monitor` | Show thread usage report after execution. See [Options: Thread budget](../configuration/options.md#thread-budget). |
| `--no-ci` | Disable auto-detection of CI annotations (GitHub Actions / GitLab CI). See [CI Annotations](../how-to/ci-cd.md#ci-annotations). |
| `--show-progress` | Force progress emission on stderr even when not a TTY. Useful in CI with `--format=json\|junit\|sarif\|codeclimate` to make long pipelines visible in the runner log. |
| `--config=PATH` | Path to configuration file. |

## Examples

```bash
githooks flow qa                                    # Run the 'qa' flow
githooks flow lint --fail-fast                      # Run with fail-fast
githooks flow qa --exclude-jobs=phpunit,phpcpd      # Skip specific jobs
githooks flow qa --only-jobs=phpstan_src,phpmd_src  # Run only these jobs
githooks flow qa --dry-run                          # Show commands without running
githooks flow qa --dry-run --format=json            # Dry-run with JSON output
githooks flow qa --processes=4                      # Run with 4 parallel processes
githooks flow qa --format=json                      # JSON v2 (AI / CI / scripts), to stdout
githooks flow qa --format=junit                     # JUnit XML for test reporting, to stdout
githooks flow qa --format=codeclimate               # GitLab Code Quality report, to stdout
githooks flow qa --format=sarif                     # SARIF 2.1.0, to stdout
githooks flow qa --format=sarif --output=reports/qa.sarif   # SARIF to a file
githooks flow qa --report-sarif=reports/qa.sarif --report-junit=reports/junit.xml \
                                                    # Multi-report: 4 formats in one run
                 --report-codeclimate=reports/cc.json --report-json=reports/qa.json
githooks flow qa --format=json --no-reports         # AI/script: JSON to stdout, no side-effect files
githooks flow qa --fast                             # Only staged files
githooks flow qa --fast-branch                      # Only branch diff files
githooks flow qa --monitor                          # Show thread usage report
githooks flow qa --no-ci                            # Opt out of CI annotations
githooks flow qa --config=qa/custom-githooks.php    # Use custom config
```

## Structured output

- **`--format=json`** emits JSON v2: top-level `version`, `executionMode` (reflects the actual `--fast`/`--fast-branch` flag), `passed`, `failed`, `skipped`, and a `jobs` array with `type`, `exitCode`, `paths`, `skipped`, `skipReason`, `fixApplied`, `command` and `output`. Jobs cancelled by `--fail-fast` appear with `skipped: true` and `skipReason: "skipped by fail-fast"`.
- **`--format=junit`** emits JUnit XML compatible with `mikepenz/action-junit-report` and similar. Skipped jobs emit `<skipped>` elements.
- **`--format=codeclimate`** emits a GitLab Code Quality JSON array. `location.path` is relative to the CWD.
- **`--format=sarif`** emits a SARIF 2.1.0 report for GitHub Code Scanning. `artifactLocation.uri` is relative to the CWD.

All four structured formats print to **stdout** by default. Pass `--output=PATH` to write the payload to a file instead (equivalent to `--format=FORMAT > PATH` for scripts that already rely on shell redirection).

### stderr progress is TTY-aware

Progress lines (`OK`, `Done.`, colours) write to **stderr** **only when a TTY is attached** (interactive terminal). From scripts, agents, pipes or CI stderr is **silent by default** — `--format=json | jq …` works without `2>/dev/null`:

```bash
# No redirection needed — stderr is naturally empty off a TTY
githooks flow qa --format=json | jq '.jobs[] | select(.success == false)'
```

Force progress in long-running CI pipelines with `--show-progress`:

```bash
githooks flow qa --format=json --show-progress --output=reports/qa.json
```

`--dry-run` never emits progress (no real execution to measure). See [How-To: Output Formats](../how-to/output-formats.md) for the full schema and stderr rules.

## Exit codes

| Code | Meaning |
|---|---|
| `0` | All jobs passed. |
| `1` | One or more jobs failed. |

## See also

- [Configuration: Flows](../configuration/flows.md)
- [Configuration: Options](../configuration/options.md)
- [`githooks job`](job.md) — run a single job.
