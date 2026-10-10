# Migration

Guides for moving onto GitHooks v3 from previous versions or competing tools. Pick the route that matches your starting point.

## Coming from GitHooks v2

Single most-asked migration. v3 changes the configuration shape from `Options` / `Tools` to `hooks` / `flows` / `jobs`, adds [execution modes](../execution-modes.md) (`--fast`, `--fast-branch`, `--fast-dirty`), per-job and flow-level [budgets](../configuration/options.md#time-budget-time-budget), [job dependencies](../configuration/flows.md#job-dependencies-needs), [branch-driven execution](../configuration/flows.md#branch-driven-execution-mode-on) and [per-entry admission rules](../configuration/flows.md#per-entry-admission-rules-only-files-exclude-files).

| Guide | When |
|---|---|
| [Upgrading from v2](v2-to-v3.md) | You have a `githooks.yml`, or a `githooks.php` with `Options:` and `Tools:`, or jobs with camelCase keys (`executablePath`, `failFast`…). 4.0 does not read any of them: migrate with GitHooks 3.8 (`conf:migrate`) first, or follow the manual route in the guide. |

## Coming from another tool

| Guide | When |
|---|---|
| [From GrumPHP](from-grumphp.md) | You currently run [GrumPHP](https://github.com/phpro/grumphp) (`grumphp.yml`, YAML tasks). |
| [From CaptainHook](from-captainhook.md) | You currently run [CaptainHook](https://captainhook.dev/) (`captainhook.json`, PHP action classes). |

If you are evaluating GitHooks against alternatives before committing, read [Comparison](../comparison.md) first — it lays out the trade-offs without picking sides.

## Fast path

If your project is on a recent v3 and you only want the latest features:

1. Read the [Changelog](../changelog.md) for the version you are upgrading to.
2. Run [`githooks conf:check`](../cli/conf-check.md). It reports deprecations and unknown keys with *did-you-mean* suggestions.
3. Fix what `conf:check` reports.

That covers the vast majority of in-major upgrades. The dedicated guides above are only needed for cross-tool or cross-major moves.
