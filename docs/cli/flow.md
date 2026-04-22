# githooks flow

Run a flow by name. A flow executes its configured jobs with the flow's options.

## Synopsis

```
githooks flow <name> [options]
```

## Options

| Option | Description |
|---|---|
| `--fail-fast` | Stop on first job failure. Overrides config value. |
| `--processes=N` | Number of parallel processes. Overrides config value. |
| `--exclude-jobs=a,b` | Comma-separated list of jobs to skip. |
| `--only-jobs=a,b` | Comma-separated list of jobs to run (others skipped). Cannot combine with `--exclude-jobs`. |
| `--dry-run` | Show commands without executing. Works with all `--format` options. |
| `--format=FORMAT` | Output format: `text` (default), `json`, `junit`, `codeclimate`, `sarif`. |
| `--output=PATH` | Custom path for the `codeclimate` / `sarif` report file. Ignored for `text`, `json` and `junit`. |
| `--stdout` | Print the `codeclimate` / `sarif` payload to stdout instead of writing a file. |
| `--fast` | Fast mode — accelerable jobs analyze only staged files. |
| `--fast-branch` | Fast-branch mode — analyze files that differ from main branch. |
| `--monitor` | Show thread usage report after execution. |
| `--no-ci` | Disable auto-detection of CI annotations (GitHub Actions / GitLab CI). |
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
githooks flow qa --format=json                      # JSON v2 (AI / CI / scripts)
githooks flow qa --format=junit                     # JUnit XML for test reporting
githooks flow qa --format=codeclimate               # GitLab Code Quality report
githooks flow qa --format=sarif --stdout            # SARIF 2.1.0 to stdout
githooks flow qa --format=sarif --output=reports/qa.sarif   # SARIF to custom path
githooks flow qa --fast                             # Only staged files
githooks flow qa --fast-branch                      # Only branch diff files
githooks flow qa --monitor                          # Show thread usage report
githooks flow qa --no-ci                            # Opt out of CI annotations
githooks flow qa --config=qa/custom-githooks.php    # Use custom config
```

## Structured output

- **`--format=json`** emits JSON v2: top-level `version`, `executionMode`, `passed`, `failed`, `skipped`, and a `jobs` array with `type`, `exitCode`, `paths`, `skipped`, `skipReason`, `fixApplied`, `command` (dry-run only) and `output`.
- **`--format=junit`** emits JUnit XML compatible with `mikepenz/action-junit-report` and similar. Skipped jobs emit `<skipped>` elements.
- **`--format=codeclimate`** emits a GitLab Code Quality JSON array. Defaults to `gl-code-quality-report.json`; override with `--output=PATH` or `--stdout`.
- **`--format=sarif`** emits a SARIF 2.1.0 report for GitHub Code Scanning. Defaults to `githooks-results.sarif`; override with `--output=PATH` or `--stdout`.

For structured formats, progress (`OK`, `Done.`, colours) writes to **stderr** and the structured payload stays on **stdout**. Redirect stderr to silence the progress when piping:

```bash
githooks flow qa --format=json 2>/dev/null | jq '.jobs[] | select(.success == false)'
```

See [How-To: Output Formats](../how-to/output-formats.md) for the full schema.

## Exit codes

| Code | Meaning |
|---|---|
| `0` | All jobs passed. |
| `1` | One or more jobs failed. |

## See also

- [Configuration: Flows](../configuration/flows.md)
- [Configuration: Options](../configuration/options.md)
- [`githooks job`](job.md) — run a single job.
