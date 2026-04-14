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
| `--format=FORMAT` | Output format: `text` (default), `json`, `junit`. |
| `--fast` | Fast mode — accelerable jobs analyze only staged files. |
| `--fast-branch` | Fast-branch mode — analyze files that differ from main branch. |
| `--monitor` | Show thread usage report after execution. |
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
githooks flow qa --format=json                      # JSON output for CI and AI integration
githooks flow qa --format=junit                     # JUnit XML for test reporting
githooks flow qa --fast                             # Only staged files
githooks flow qa --fast-branch                      # Only branch diff files
githooks flow qa --monitor                          # Show thread usage report
githooks flow qa --config=qa/custom-githooks.php    # Use custom config
```

## Structured output

The `--format=json` option outputs a JSON object with the flow name, overall success, total time, and an array of job results (name, success, time, output, fixApplied).

The `--format=junit` option outputs JUnit XML compatible with CI test reporting tools.

See [How-To: Output Formats](../how-to/output-formats.md) for examples.

## Exit codes

| Code | Meaning |
|---|---|
| `0` | All jobs passed. |
| `1` | One or more jobs failed. |

## See also

- [Configuration: Flows](../configuration/flows.md)
- [Configuration: Options](../configuration/options.md)
- [`githooks job`](job.md) — run a single job.
