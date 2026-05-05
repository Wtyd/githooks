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
- Unknown configuration keys (warnings, with did-you-mean suggestions for typos).
- [Meta-flows](../configuration/flows.md#meta-flows): each `flows.<X>` declares exactly one of `jobs` or `flows`; `flows.<alias>.flows` only references existing **normal** flows (no nesting); the jobs/flows/meta-flows namespace is flat.
- [Multi-report](../configuration/options.md#multi-report): only `json`, `junit`, `sarif`, `codeclimate` keys are accepted; paths must be strings and writable.
- [Time budget](../configuration/options.md#time-budget-time-budget): positive integers; `warn-after < fail-after`; `time-budget` rejected inside a job (canonical keys at job level are flat `warn-after` / `fail-after`).
- [Memory budget](../configuration/options.md#memory-budget-memory-budget): positive integers; `warn-above < fail-above`; per-job `memory > memory-budget.warn-above` is flagged as a could-never-run configuration.
- [Allocator](../configuration/options.md#allocator-strategy-allocator): only `fifo` and `greedy` are valid.
- `--files` / `--files-from`: rejected when declared as keys inside `flow.options` or a job (CLI-only by design).

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
