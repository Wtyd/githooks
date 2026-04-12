# githooks status

Show the state of installed hooks and their synchronization with the configuration.

## Synopsis

```
githooks status [--config=PATH]
```

## Output

Shows:

- **hooks path** — whether `core.hooksPath` is configured to `.githooks/`.
- **Event table** — each configured hook event with its status and targets:

| Status | Meaning |
|---|---|
| **synced** | Installed and matches configuration. |
| **missing** | Configured but not installed. Run `githooks hook` to fix. |
| **orphan** | Installed but not in configuration. |

## Examples

```bash
githooks status
githooks status --config=qa/githooks.php
```

## See also

- [`githooks hook`](hook.md) — install hooks.
