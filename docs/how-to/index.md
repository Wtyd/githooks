# How-To Guides

Practical recipes for common scenarios. Each guide stands alone — read the ones you need, in any order. For broader reference, see [Configuration](../configuration/index.md), [CLI](../cli/index.md) and [Tools](../tools/index.md).

## Performance

| Guide | When to read it |
|---|---|
| [Parallel Execution & Thread Budget](parallel-execution.md) | Your `qa` flow is slow. You want to spread cores across tools, set a `time-budget` / `memory-budget`, or understand the 2D allocator. |

## Filtering inputs

| Guide | When to read it |
|---|---|
| [Conditional Hooks](conditional-hooks.md) | You want different tools on different branches, or to skip a hook when the change set doesn't touch certain paths. |
| [`--files` / `--files-from` / `--exclude-pattern`](files-flag.md) | You have an explicit list of files (IDE on-save, shallow CI checkout, `git diff` manifest) and want to run a flow against only those files. |

## CI / CD

| Guide | When to read it |
|---|---|
| [CI/CD Integration](ci-cd.md) | You are wiring GitHooks into GitHub Actions, GitLab CI, or any pipeline. Includes JUnit / SARIF / Code Climate report integration and CI annotations. |
| [Output Formats](output-formats.md) | You need JSON v2, JUnit, SARIF or Code Climate output for an automation, IDE or CI dashboard. |

## Sharing configuration across environments

| Guide | When to read it |
|---|---|
| [Docker & Local Override](docker-local-override.md) | You run GitHooks inside Docker / Sail / a remote runner and want a per-developer `.local.php` override for `executable-prefix`. |
| [Automating Hook Installation](automate-install.md) | You want hooks installed automatically for every team member after `composer install`. |
| [Job Inheritance](job-inheritance.md) | You have several similar jobs (e.g. `phpcs_src`, `phpcs_tests`) and want to share a base definition with `extends`. |

## Non-PHP tools

| Guide | When to read it |
|---|---|
| [Frontend Tools](frontend-tools.md) | You want ESLint, Prettier, `composer audit`, `npm audit`, or any non-PHP command to run inside the same flow as your PHP QA. |

## When something goes wrong

Not technically a how-to, but useful next to this list:

- [Troubleshooting](../troubleshooting.md) — error messages from `conf:check`, runtime `skipReason` values, CI-specific edge cases.
- [Glob syntax reference](../glob-syntax.md) — the pattern language used across `only-files` / `exclude-files` / `--exclude-pattern` / hook conditions.
