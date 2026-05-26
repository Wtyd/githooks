# CLI Reference

GitHooks is built on [Laravel Zero](https://laravel-zero.com/). Run `githooks list` to see all available commands. Every command that reads a configuration file accepts `--config=PATH` (absolute or relative); every command that produces structured output accepts `--format=json` and writes a stable [JSON v2 schema](../how-to/output-formats.md#json-v2) on stdout.

The commands fall into four groups by purpose.

## Execution

Run a flow, a combined plan or a single job. These commands honour the [execution modes](../execution-modes.md), the [thread budget](../configuration/options.md#thread-budget), the time and memory budgets, and the structured-output contracts.

| Command | What it does |
|---|---|
| [`githooks flow <name>`](flow.md) | Run a single flow. |
| [`githooks flows <name1> <name2>…`](flows.md) | Run several flows or a declarative [meta-flow](../configuration/flows.md#meta-flows) as a single combined plan. Replaces the "two CI steps with two `composer install`" pattern with a single invocation. |
| [`githooks job <name>`](job.md) | Run a single job in isolation. Useful when iterating on one tool's config. Accepts `-- <args>` to forward extra arguments to the underlying tool. |

## Configuration

Generate, validate and upgrade the configuration file.

| Command | What it does |
|---|---|
| [`githooks conf:init`](conf-init.md) | Generate a `githooks.php` template, optionally auto-detecting tools under `vendor/bin/`. Interactive or non-interactive. |
| [`githooks conf:check`](conf-check.md) | Deep validation: structure, references, paths, executables, configs, deprecations and *did-you-mean* suggestions. Exits 1 on the first error. The first thing to run when something looks wrong — see [Troubleshooting](../troubleshooting.md). |
| [`githooks conf:migrate`](conf-migrate.md) | One-shot conversion from the legacy v2 `Options/Tools` shape to the v3 `hooks/flows/jobs` shape. |

## Inspection

Look at the system, the install, and the on-disk state without modifying anything.

| Command | What it does |
|---|---|
| [`githooks status`](status.md) | Show whether hooks are installed for the current repository and which `core.hooksPath` is active. |
| [`githooks system:info`](system-info.md) | Show CPU detection (cgroup-aware) and how `processes` resolves on the current host. Use to size budgets in CI runners. |
| [`githooks cache:clear`](cache-clear.md) | Clear QA tool cache directories. Resolves the **effective** cache path per tool (reads `.neon` / `psalm.xml` / `phpunit.xml` / etc.) rather than relying on hard-coded defaults. |

## Hook management

| Command | What it does |
|---|---|
| [`githooks hook`](hook.md) | Install git hooks via `core.hooksPath = .githooks` (no overwrite of `.git/hooks/`). Pre-existing hook scripts are backed up to `<hook>.bak`. |

## Common flags

Most flags are shared across `flow`, `flows` and `job`. Rather than repeat them here, see each command page — or [Configuration: Options](../configuration/options.md) for the canonical reference (the page CLI flags override).
