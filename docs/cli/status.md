# alfil status

Show the state of installed hooks and their synchronization with the configuration.

## Synopsis

```
alfil status [--config=PATH] [--format=text|json]
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
| **missing** | Configured but not installed. Run `alfil hook` to fix. |
| **orphan** | Installed but not in configuration. |

### JSON payload

```json
{
  "version": 1,
  "hooksPath": { "configured": true, "value": ".githooks" },
  "events": [
    { "event": "pre-commit", "status": "synced", "executable": true, "targets": ["qa"], "callsRemovedBinary": false }
  ]
}
```

Targets are emitted raw. `callsRemovedBinary` is `true` when the installed script still runs the `githooks` binary of 3.x, which no longer exists: the event looks synced but fails on every trigger until `alfil hook` regenerates the scripts. The text report lists those events with the same hint. A configuration that cannot be read (missing, v2 shape,
YAML) produces `{"version":1,"error":"…"}` and exit code 1.

## Examples

```bash
alfil status
alfil status --config=qa/alfil.php
alfil status --format=json
```

## See also

- [`alfil hook`](hook.md) — install hooks.
