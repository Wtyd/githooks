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
- [Cores / native thread flag](../configuration/jobs.md#reserving-cores-cores-or-the-tools-native-flag): `cores` must be a positive integer; declaring both `cores` and the tool's native flag (`parallel`, `threads`, `jobs`, `processes`) emits a conflict warning; declaring `cores > 1` on a single-threaded tool (`phpmd`, `phpunit`, `phpcpd`) emits a no-benefit warning.
- [The flow rules](../configuration/jobs.md#the-flow-rules): for uncontrollable jobs whose declaration cannot be clamped by the runtime (`phpstan` reads `.neon`, `type: custom` is opaque), `conf:check` emits a cross-flow warning when `cores` (custom) or `maximumNumberOfProcesses` (phpstan) exceeds a flow's `processes`. The warning names the affected flow and explains that other jobs in it will wait in serial while the offending job runs. Same job referenced from multiple flows is validated against each flow independently.
- `--files` / `--files-from`: rejected when declared as keys inside `flow.options` or a job (CLI-only by design).
- [Per-entry admission rules (`only-files` / `exclude-files`)](../configuration/flows.md#per-entry-admission-rules-only-files-exclude-files): flow entries declared as `{job, only-files?, exclude-files?}` accept arrays of glob strings or `null` (cancels an inherited rule from `.local.php`). Empty list `[]` is rejected with a pointer to `null` for the cancel-inheritance pattern.
- [Branch-driven execution mode (`on`)](../configuration/flows.md#branch-driven-execution-mode-on): `flows.<X>.on` must be a `branch_pattern => attrs` map. Each `attrs` array accepts only scalar keys (today only `execution`). Unsupported `execution` values are rejected with a did-you-mean suggestion (`full`, `fast`, `fast-branch`, `fast-dirty`). Missing catch-all `*` pattern emits a warning. `flows.<X>.on.<branch>.execution` typos surface the same did-you-mean.
- [Job dependencies (`needs`)](../configuration/flows.md#job-dependencies-needs): the per-flow DAG is validated with DFS. Cycles of any length (`A -> A`, `A -> B -> A`, …) are rejected with the offending chain in the error message. `needs` targets must exist as job declarations in the same flow. The same job declared twice in `flows.<X>.jobs` is rejected. An empty `needs => []` list is rejected — the user is pointed at `null` (which cancels an inherited rule from `.local.php`).
- [Fast-dirty execution mode (`fast-dirty`)](../execution-modes.md#fast-dirty-mode-fast-dirty): accepted as a valid value of `execution` in `flows.<X>.execution`, `flows.<X>.on.<branch>.execution` and `jobs.<X>.execution`, alongside `full`, `fast`, `fast-branch`.

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
