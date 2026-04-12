# githooks job

Run a single job in isolation. Useful for debugging job configuration or running a specific check.

## Synopsis

```
githooks job <name> [options]
```

## Options

| Option | Description |
|---|---|
| `--dry-run` | Show command without executing. |
| `--format=FORMAT` | Output format: `text` (default), `json`, `junit`. |
| `--fast` | Fast mode — analyze only staged files. |
| `--fast-branch` | Fast-branch mode — analyze branch diff files. |
| `--config=PATH` | Path to configuration file. |

## Examples

```bash
githooks job phpstan_src                  # Run a single job
githooks job phpstan_src --dry-run        # Show command without running
githooks job phpunit_all --format=json    # JSON output
githooks job phpcs_src --fast             # Only staged files
```

## Exit codes

| Code | Meaning |
|---|---|
| `0` | Job passed. |
| `1` | Job failed. |

## See also

- [Configuration: Jobs](../configuration/jobs.md)
- [`githooks flow`](flow.md) — run a group of jobs.
