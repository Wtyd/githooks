# githooks conf:check

Validate the configuration file with deep checks.

## Synopsis

```
githooks conf:check [--config=PATH]
```

## What it checks

- File exists in the expected location.
- Structure is correct (hooks/flows/jobs).
- Job types are supported.
- Argument types are valid (paths must be array, rules must be string, etc.).
- Flow and hook references point to existing jobs/flows.
- Hook names are valid git events.
- Unknown configuration keys (warnings).

### Deep validation

The output includes tables with Options, Hooks, Flows, Jobs and the full command each job will execute. The Jobs table includes a **Status** column with:

- **Executable** — checks that the binary exists in the filesystem or PATH.
- **Paths** — checks that configured directories exist.
- **Config files** — checks that referenced config files (`.neon`, `.xml`) are accessible.

## Error levels

- **Errors** prevent execution.
- **Warnings** allow execution but indicate potential issues.

## Examples

```bash
githooks conf:check
githooks conf:check --config=qa/custom-githooks.php
```

## See also

- [Configuration File](../configuration/file.md)
- [`githooks conf:init`](conf-init.md) — generate a configuration file.
