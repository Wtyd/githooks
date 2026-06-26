# githooks status

Show the state of installed hooks and their synchronization with the configuration.

## Synopsis

```
githooks status [--config=PATH] [--format=text|json]
```

## Options

| Option | Description |
|---|---|
| `--config=PATH` | Path to configuration file. |
| `--format=text\|json` | Output format: `text` (default, human-readable table) or `json` (machine-readable, clean stdout). An unknown value warns on stderr and falls back to `text`. |

## Output

Shows:

- **hooks path** — whether `core.hooksPath` is configured to `.githooks/`.
- **Event table** — each configured hook event with its status and targets:

| Status | Meaning |
|---|---|
| **synced** | Installed and matches configuration. |
| **missing** | Configured but not installed. Run `githooks hook` to fix. |
| **orphan** | Installed but not in configuration. |

### JSON payload

```json
{
  "version": 1,
  "hooksPath": { "configured": true, "value": ".githooks" },
  "events": [
    { "event": "pre-commit", "status": "synced", "executable": true, "targets": ["qa"] }
  ]
}
```

Targets are emitted raw. A legacy configuration produces `{"version":1,"error":"…"}`
and exit code 1.

## Examples

```bash
githooks status
githooks status --config=qa/githooks.php
githooks status --format=json
```

## See also

- [`githooks hook`](hook.md) — install hooks.
