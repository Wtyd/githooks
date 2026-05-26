# githooks flows

Run several flows — or a declarative meta-flow — as a **single plan**: one PHP runtime, one shared thread budget, one combined `FlowResult`. Designed to replace "two CI steps that each run `composer install` and a separate `flow`" with a single `flows ci-pack`.

## Synopsis

```
githooks flows <name1> <name2> ... [<nameN>] [options]
```

Accepts one or more positional names. Each name can be a normal flow or a **meta-flow** declared in config (see [Configuration: Flows](../configuration/flows.md#meta-flows)). Mixing both is allowed.

## Invocation modes

`flows` selects its option-resolution cascade automatically based on what you pass:

| Args | Mode | Cascade for `processes` / `fail-fast` / mode | `flow` (JSON) | `flows[]` (JSON) |
|---|---|---|---|---|
| 1 normal flow (`flows qa`) | **single-flow degenerate** | `cli > flows.qa.options > flows.options > default` | `"qa"` | absent |
| 1 meta-flow (`flows ci-pack`) | **declarative** | `cli > flows.ci-pack.options > flows.options > default` | `"ci-pack"` | `["qa","lint"]` |
| ≥ 2 normal flows (`flows qa lint`) | **ad-hoc** | `cli > flows.options > default` (per-flow options ignored) | `"qa+lint"` | `["qa","lint"]` |
| ≥ 2 args, ≥ 1 meta (`flows ci-pack other`) | **mixed** | `cli > flows.options > default` (alias options ignored) | `"ci-pack+other"` | expanded list |

In ad-hoc and mixed modes, options declared on per-flow or per-meta-flow get a one-time **warning** so you know they were ignored:

```
⚠ Options declared in 'qa' are ignored in multi-flow runs.
  Effective options come from flows.options + CLI; see header below.
```

Single-flow degenerate runs (`flows qa` with `qa` a normal flow) produce **the same `FlowResult`** as `flow qa` — same job order, same `effectiveOptions`, no `flows[]` field. There is no penalty for adopting `flows` as the canonical entry point in a CI pipeline.

## Options

The same flag set as [`flow`](flow.md): `--fail-fast`, `--processes`, `--exclude-jobs`, `--only-jobs`, `--format`, `--output`, `--report-*`, `--no-reports`, `--fast`, `--fast-branch`, `--fast-dirty`, `--fast-branch-fallback`, `--files`, `--files-from`, `--exclude-pattern`, `--monitor`, `--no-ci`, `--show-progress`, `--config`, `--dry-run`. Only the positional argument changes (variadic instead of single).

!!! note "`--branch=X` is `flow`-only"
    The `--branch=X` flag for [branch-driven execution mode (`on`)](../configuration/flows.md#branch-driven-execution-mode-on) is only registered on [`flow`](flow.md#options) in 3.4. Inside `flows`, branch detection falls back to `$GITHOOKS_BRANCH`, CI env vars and `git rev-parse --abbrev-ref HEAD`.

`--exclude-jobs` and `--only-jobs` apply to the **merged deduplicated list** of jobs after meta-flow expansion, not per source flow. `--files` / `--files-from` build a **single** shared file-set that every job consumes.

## Conditions header

Every `flow` / `flows` / `job` run prints a header in text mode (and in structured mode with `--show-progress`) with the resolved options and their source — one row per option, aligned, every row carries its `(source)` parenthesis (including `(default)`):

```
Settings:
  processes     = 4    (cli)
  fail-fast     = true (flows.ci-pack.options)
  mode          = full (default)
  time-budget   = none (default)
  memory-budget = none (default)
  allocator     = fifo (default)
  stats         = false (default)
Flows: qa, lint
```

The `Flows:` line appears in declarative, ad-hoc, and mixed modes; it is omitted in `flow X` and single-flow degenerate runs.

## Examples

```bash
# Single-flow degenerate — equivalent to `flow qa`
githooks flows qa

# Ad-hoc combination of two normal flows
githooks flows qa lint

# Declarative meta-flow (defined in config under flows.ci-pack)
githooks flows ci-pack

# Override processes; CLI wins over the meta-flow's options
githooks flows ci-pack --processes=8

# Mixed: meta-flow + normal flow; meta-flow options are ignored (warning emitted)
githooks flows ci-pack deploy

# Multi-report from one combined run
githooks flows ci-pack --report-sarif=qa.sarif --report-junit=qa.xml

# JSON v2 with the new effectiveOptions block and flows[] root field
githooks flows ci-pack --format=json | jq '{flow, flows, effectiveOptions}'

# Files mode applied to the combined union
githooks flows ci-pack --files-from=/tmp/changed.txt
```

## JSON v2 contract additions

Two new root fields land in v2 alongside the existing `version`, `flow`, `success`, `executionMode`, `jobs[]`, etc.:

- **`flows[]`** *(optional)*: the list of normal flows actually executed after meta-flow expansion. Absent in `flow X` and single-flow degenerate runs.
- **`effectiveOptions`** *(always present in `flow`/`flows`/`job` runs)*: per-key `{value, source}` for `processes`, `failFast`, `executionMode`, and `mainBranch` when applicable. `source` is one of `cli`, `flows.<X>.options`, `flows.<alias>.options`, `flows.options`, or `default`.

```json
{
  "version": 2,
  "flow": "ci-pack",
  "flows": ["qa", "lint"],
  "effectiveOptions": {
    "processes":     { "value": 4,    "source": "flows.ci-pack.options" },
    "failFast":      { "value": true, "source": "flows.ci-pack.options" },
    "executionMode": { "value": "full", "source": "default" }
  },
  "jobs": [...]
}
```

Consumers that ignore the new fields keep working — both are additive.

## Exit codes

| Code | Meaning |
|---|---|
| `0` | All jobs in the combined plan passed. |
| `1` | One or more jobs failed, an unknown flow name was passed, or `--exclude-jobs` and `--only-jobs` were combined. |

## See also

- [Configuration: Flows — Meta-flows](../configuration/flows.md#meta-flows)
- [How-To: CI/CD Integration — one `flows` for CI](../how-to/ci-cd.md)
- [How-To: Output Formats — Conditions header](../how-to/output-formats.md)
- [`githooks flow`](flow.md) — single-flow runs.
- [`githooks job`](job.md) — single-job runs.
