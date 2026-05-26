# Comparison

GitHooks, [GrumPHP](https://github.com/phpro/grumphp) and [CaptainHook](https://captainhook.dev/) all manage git hooks for PHP projects and can run the same QA tools. They differ mainly in **how they are distributed and configured** — design choices that are unlikely to change, unlike individual features, which all three projects add over time.

!!! note
    This page compares structural design, not a feature-by-feature scorecard (those age badly and rarely decide a real choice). Written against GitHooks 3.4, GrumPHP 2.x and CaptainHook 5.x — verify the current state of each project before deciding.

## Structural differences

| | GitHooks | GrumPHP | CaptainHook |
|---|---|---|---|
| **Distribution** | Standalone `.phar` | Composer dependency | Composer dependency |
| **Dependency isolation** | The `.phar` bundles its own dependencies, so they never clash with your project's | Shares your project's Composer dependencies | Shares your project's Composer dependencies |
| **Configuration** | PHP file, declarative (`type` + keywords) | YAML, task-based | JSON, with PHP action classes or shell commands |
| **Git events** | All events | Pre-commit oriented | All events |
| **Extensibility** | Built-in tool types + a `custom` type that runs any command | Plugin/extension ecosystem | PHP action classes + plugins |

The headline structural choice is the first row: GitHooks ships as a standalone `.phar` that embeds its own dependencies, while GrumPHP and CaptainHook install as Composer dev-dependencies of your project. That single decision drives the dependency-isolation difference and most of the trade-offs below.

## What's distinctive about GitHooks

Beyond distribution, these are the capabilities GitHooks is built around. They are described here as design intent, not as a checklist against the others:

- **Execution modes** — `full`, `--fast` (staged files), `--fast-branch` (branch diff) and `--fast-dirty` (the unified working tree, useful for AI agentic hooks). See [Execution Modes](execution-modes.md).
- **Thread budget** — `processes` is an absolute core ceiling distributed across tools that support internal parallelism, with optional per-job reservations and flow-level time / memory budgets. See [Parallel execution](how-to/parallel-execution.md).
- **Declarative flows** — group jobs into named flows with intra-flow dependencies (`needs`), per-branch execution (`on`) and per-entry admission rules (`only-files` / `exclude-files`), so the flow declaration carries the policy instead of the CI YAML. See [Flows](configuration/flows.md).
- **Structured output** — JSON v2, JUnit, SARIF 2.1.0 and Code Climate, with multi-report in a single run, for CI dashboards and automation. See [Output formats](how-to/output-formats.md).

## Choosing between them

**Choose GitHooks when** dependency isolation matters (the `.phar` never touches your `composer.json` resolution), when you want a single declarative config that drives both local hooks and CI, or when you need the execution modes / thread budgeting / structured output above.

**Consider GrumPHP when** you rely on its plugin ecosystem — it ships extensions for commit-message conventions, file-size checks, security audits and more. If a specific extension covers your need out of the box, that is a real convenience. (GitHooks' `custom` type can run any command, so most gaps are bridgeable, but you write the glue.)

**Consider CaptainHook when** your hooks need sophisticated PHP logic. CaptainHook actions are PHP classes, which gives you the full language for conditions and side effects — more than a declarative config or a shell command expresses comfortably.

All three are mature and actively maintained; none is a wrong choice. The deciding factor is usually the distribution model (isolated `.phar` vs Composer dependency) and the configuration style that fits your team.

## Migration guides

If you are moving from one of the others:

- [From GrumPHP](migration/from-grumphp.md)
- [From CaptainHook](migration/from-captainhook.md)
