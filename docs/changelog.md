# Changelog

All notable changes to this project are documented here.

## [3.2.0]

### New Features

#### Output formats and CI integration
- **Code Climate format ([`--format=codeclimate`](how-to/output-formats.md#code-climate))**: Emits a GitLab-compatible Code Quality report. Writes to `gl-code-quality-report.json` by default; supports `--output=PATH` and `--stdout`.
- **SARIF format ([`--format=sarif`](how-to/output-formats.md#sarif))**: Emits a SARIF 2.1.0 report for GitHub Code Scanning, Azure DevOps and other static-analysis consumers. Writes to `githooks-results.sarif` by default; supports `--output=PATH` and `--stdout`.
- **JSON schema v2 ([`--format=json`](how-to/output-formats.md#json-v2))**: Enriched per-job fields (`type`, `exitCode`, `paths`, `skipped`, `skipReason`, `fixApplied`) plus top-level `version: 2`, `executionMode`, `passed`, `failed`, `skipped` counters. Drop-in consumer for AI tools and CI dashboards.
- **JUnit `<skipped>` support**: Skipped jobs now emit `<skipped>` elements with a reason attribute, compatible with JUnit report consumers.
- **stdout/stderr split for structured formats**: Progress (`OK job (Xms) [Y/Z]`, `Done.` lines, colors) routes to stderr; structured payload stays on stdout. Enables `githooks flow qa --format=json > report.json` without contamination.
- **Interactive parallel dashboard ([`--monitor`](how-to/output-formats.md#interactive-dashboard))**: When running in a TTY with `processes > 1`, shows queue/running/done panes in real time. Falls back to streaming text output in non-TTY environments.
- **Native CI annotations ([CI/CD Integration](how-to/ci-cd.md#ci-annotations))**: Auto-detects `GITHUB_ACTIONS=true` or `GITLAB_CI` and wraps job output in `::group::`/`::endgroup::` plus `::error file=…,line=…::` annotations (GitHub) or `section_start:`/`section_end:` markers (GitLab). Parses `file.php:LINE` patterns from tool output.
- **`--no-ci` flag**: Opt out of auto-detection; forces plain output even when CI env vars are present.

#### New native job types
- **[PHP CS Fixer (`type: php-cs-fixer`)](tools/phpcsfixer.md)**: Native support with `config`, `rules`, `dry-run`, `diff`, `allow-risky`, `using-cache`, `cache-file` keywords. Accelerable.
- **[Rector (`type: rector`)](tools/rector.md)**: Native support with `config`, `dry-run`, `clear-cache`, `no-progress-bar` keywords. Accelerable.

#### Platform
- **Windows platform abstraction**: CPU detection on Windows via `NUMBER_OF_PROCESSORS` env var with `wmic` fallback. Cross-platform `stderrRedirect()` helper (`2>/dev/null` on POSIX, `2>nul` on Windows) for internal shell-outs.

#### Developer experience
- **Enriched `JobResult` and `FlowResult`**: Internal objects now expose `getType()`, `getExitCode()`, `getPaths()`, `isSkipped()`, `getSkipReason()`. Feeds the JSON v2 schema and downstream consumers.
- **`conf:check` command truncation**: Long generated commands are truncated to 80 chars (with `…`) in the job table to keep the check output readable on narrow terminals.

---

## [3.1.0]

### New Features
- **Local override (`githooks.local.php`)**: GitHooks looks for a `githooks.local.php` file alongside `githooks.php`. If found, its contents are merged over the main config using `array_replace_recursive`. Allows per-developer environment customization without modifying the shared config. Add `githooks.local.php` to `.gitignore`. See [Docker & Local Override](how-to/docker-local-override.md).
- **`executable-prefix` option**: New option at global, flow, and job level. Prepends a command to all job executables (e.g. `'docker exec -i app'`). Per-job override with `''` or `null` to opt out. Enables Docker, Laravel Sail, and remote environments from a single config. See [Options: executable-prefix](configuration/options.md#executable-prefix).
- **Extra arguments via `--` for `job` command**: `githooks job phpunit_all -- --filter=testFoo` passes extra flags to the underlying tool. Enables dynamic execution from AI tools, scripts, or quick debugging without modifying configuration. See [`githooks job`](cli/job.md).
- **External documentation site**: Full MkDocs Material site with getting started guide, configuration reference, CLI reference, tool docs, how-to guides, migration guides, and comparison page.

### Bug Fixes
- Fix skipped job warnings not showing orange color in terminal output.

---

## [3.0.0] - 2026-04-10

### Breaking Changes
- **PHP minimum raised to 7.4**. Dropped support for PHP 7.0-7.3.
- **SecurityChecker tool removed**. Use a [`custom` job with `composer audit`](tools/custom.md) as replacement.
- **New configuration format: hooks/flows/jobs**. Replaces the previous `Options`/`Tools` format. The old format still works but emits a deprecation warning.
- **`tool` command deprecated**. Replaced by [`flow`](cli/flow.md) and [`job`](cli/job.md) commands. Will be removed in v4.0.
- **YAML configuration deprecated**. PHP format is now the primary format. YAML still works but emits a deprecation warning. Will be removed in v4.0.

### New Architecture — Hooks, Flows, Jobs
- **[Hooks](configuration/hooks.md)**: Map git events (`pre-commit`, `pre-push`, etc.) to flows and jobs. Uses `core.hooksPath` with a universal script instead of copying files to `.git/hooks/`.
- **[Flows](configuration/flows.md)**: Named groups of jobs with shared options (`fail-fast`, `processes`). Reusable across hooks and directly executable from CLI/CI.
- **[Jobs](configuration/jobs.md)**: Individual QA tasks with declarative configuration. Each job declares a `type` (phpstan, phpcs, phpunit, custom, etc.) and its arguments.

### New Commands
- [`githooks flow <name>`](cli/flow.md) — Run a flow by name. Supports `--fail-fast`, `--processes=N`, `--exclude-jobs`, `--only-jobs`, `--dry-run`, `--format=json|junit`, `--fast`, `--fast-branch`, `--monitor`.
- [`githooks job <name>`](cli/job.md) — Run a single job by name. Supports `--dry-run`, `--format=json|junit`, `--fast`, `--fast-branch`.
- [`githooks hook:run <event>`](cli/hook.md) — Run all flows/jobs associated with a git hook event (called by the universal hook script).
- [`githooks status`](cli/status.md) — Show installed hooks, their sync state with config (synced/missing/orphan), and target flows/jobs.
- [`githooks system:info`](cli/system-info.md) — Show detected CPUs and current `processes` configuration with budget warning.
- [`githooks conf:migrate`](cli/conf-migrate.md) — Migrate v2 configuration to v3 format with automatic backup.
- [`githooks cache:clear`](cli/cache-clear.md) — Clear cache files generated by QA tools. Accepts job names, flow names, or a mix.

### Updated Commands
- [`githooks hook`](cli/hook.md) — Now uses `core.hooksPath` + `.githooks/` directory instead of copying scripts to `.git/hooks/`. `--legacy` flag preserves old behavior (Git < 2.9).
- `githooks hook:clean` — Default now removes `.githooks/` + unsets `core.hooksPath`. `--legacy` flag removes individual hooks from `.git/hooks/`.
- [`githooks conf:init`](cli/conf-init.md) — Now supports `--legacy` flag to generate v2 format.
- [`githooks conf:check`](cli/conf-check.md) — Updated for v3: shows Options, Hooks, Flows, and Jobs tables with the full command each job will execute. Deep validation: verifies executables exist, paths are valid, and config files are accessible.

### New Job Types
- **[Custom](tools/custom.md)**: Replaces the v2 `script` tool. Supports `script` key (simple mode) and a new structured mode via `executablePath` + `paths` + `otherArguments`. Structured mode enables `--fast` acceleration identical to standard tools.

### Execution Modes and Structured Output
- **[`--format=json` and `--format=junit`](how-to/output-formats.md)**: Structured output for `flow` and `job` commands. JSON for machine-readable results; JUnit XML for CI test reporting.
- **[`fast-branch` execution mode](execution-modes.md)**: New third mode alongside `full` and `fast`. Analyzes files that differ between the current branch and the main branch. Ideal for CI/CD. Non-accelerable jobs always run with full paths. Per-job `accelerable` key overrides the default. Deleted files are excluded automatically.
- **`fast-branch-fallback` option**: Controls behavior when `fast-branch` cannot compute the diff (e.g. shallow clone). Values: `full` (default) or `fast`.
- **`main-branch` option**: Configure the main branch name for `fast-branch` diff computation. Auto-detected if not specified.
- **[Thread budget](how-to/parallel-execution.md)**: `processes` now controls total CPU cores, not just parallel jobs. GitHooks distributes threads across jobs respecting each tool's capabilities (phpcs `--parallel`, parallel-lint `-j`, psalm `--threads`). PHPStan workers detected from `.neon` config.
- **`--monitor` flag**: Shows peak estimated thread usage after flow execution, with warning if budget was exceeded.
- **Job argument validation**: `conf:check` and `flow`/`job` commands validate job configuration keys and types at parse time.

### Developer Experience
- **`--dry-run` flag**: Shows the exact shell command each job would execute without running anything. Works with all output formats — `--format=json` includes a `command` field per job.
- **`--only-jobs` flag**: Inverse of `--exclude-jobs` for the `flow` command. Run only the specified jobs: `githooks flow qa --only-jobs=phpstan_src,phpmd_src`.
- **Deep validation in `conf:check`**: Checks that executables exist, that configured `paths` are real directories, and that referenced config files are accessible.
- **Auto-detection of `executablePath`**: When omitted, GitHooks looks for `vendor/bin/{tool}` before falling back to system PATH.

### Conditional Execution
- **[`exclude-files`](configuration/hooks.md#condition-keys)**: Excludes staged files matching glob patterns from triggering execution. Always prevails over `only-files`.
- **[`exclude-on`](configuration/hooks.md#condition-keys)**: Excludes branches matching glob patterns. Always prevails over `only-on`.
- **Double-star (`**`) glob support**: File patterns now support `**` for recursive directory matching. `src/**/*.php` matches all PHP files under `src/` at any depth.
- **`hooks.command` config key**: Customize the command used in generated hook scripts (e.g. `'command' => 'php7.4 vendor/bin/githooks'`).
