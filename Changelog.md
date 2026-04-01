# Changelog

All notable changes to this project will be documented in this file.

## [3.0.0] - Unreleased

### Breaking Changes
- **PHP minimum raised to 7.4**. Dropped support for PHP 7.0–7.3. Build tier `php7.1` eliminated (`builds/php7.1/`, `tools/php71/`).
- **SecurityChecker tool removed**. Since Composer 2.4, `composer audit` covers this functionality. Use a `custom` job as replacement (see dist files for example).
- **New configuration format: hooks/flows/jobs**. Replaces the previous `Options`/`Tools` format. The old format still works but emits a deprecation warning.
- **`tool` command deprecated**. Replaced by `flow` and `job` commands. Will be removed in v4.0.
- **YAML configuration deprecated**. PHP format is now the primary format. YAML still works but emits a deprecation warning. Will be removed in v4.0.

### New Architecture — Hooks, Flows, Jobs
- **Hooks**: Map git events (`pre-commit`, `pre-push`, etc.) to flows and jobs. Uses `core.hooksPath` with a universal script instead of copying files to `.git/hooks/`.
- **Flows**: Named groups of jobs with shared options (`fail-fast`, `processes`). Reusable across hooks and directly executable from CLI/CI.
- **Jobs**: Individual QA tasks with declarative configuration. Each job declares a `type` (phpstan, phpcs, phpunit, custom, etc.) and its arguments.

### New Commands
- `githooks flow <name>` — Run a flow by name. Supports `--format=json|junit`, `--fast`, `--exclude-jobs`.
- `githooks job <name>` — Run a single job by name. Supports `--format=json|junit`, `--fast`.
- `githooks hook run <event>` — Run all flows/jobs associated with a git hook event (called by the universal hook script).
- `githooks hook` — Install git hooks via `core.hooksPath`.
- `githooks status` — Show installed hooks, their sync state with config (synced/missing/orphan), and target flows/jobs.
- `githooks system:info` — Show detected CPUs and current `processes` configuration.
- `githooks conf:migrate` — Migrate v2 configuration (Options/Tools) to v3 format (hooks/flows/jobs) with automatic backup.
- `githooks conf:check` — Updated for v3: shows Options, Hooks, Flows, and Jobs tables with the full command each job will execute.

### New Job Types
- **Custom**: Run any command via `script` key. Replaces the `script` tool and enables integration of non-native tools (e.g. `composer audit`, `eslint`, `php-cs-fixer`).

### New Configuration Classes
- `ConfigurationParser`, `ConfigurationResult`, `FlowConfiguration`, `HookConfiguration`, `JobConfiguration`, `OptionsConfiguration`, `ValidationResult` — replace the monolithic `ConfigurationFile`.

### New Execution Engine
- `FlowExecutor`, `FlowPreparer`, `FlowPlan`, `FlowResult`, `JobExecutor`, `JobResult` — replace `ToolsPreparer` and `ProcessExecution*` for v3 commands.
- `HookRunner`, `HookInstaller` — orchestrate hook resolution and installation.

### Observability and Structured Output
- **`--format=json` and `--format=junit`**: Structured output for `flow` and `job` commands. JSON for machine-readable results; JUnit XML for CI test reporting integration.
- **Grouped error output**: In parallel execution, success lines print in real-time while error details are collected and printed grouped at the end.
- **Fast mode for custom jobs**: `--fast` flag (or automatically on `pre-commit` hooks) exposes `$GITHOOKS_STAGED_FILES` environment variable to custom job scripts with the list of staged files.
- **Thread budget**: `processes` now controls total CPU cores, not just parallel jobs. GitHooks distributes threads across jobs respecting each tool's capabilities (phpcs `--parallel`, parallel-lint `-j`, psalm `--threads`). PHPStan workers detected from `.neon` config.
- **`--monitor` flag**: Shows peak estimated thread usage after flow execution, with warning if budget was exceeded.
- **Job argument validation**: `conf:check` and `flow`/`job` commands validate job configuration keys and types at parse time against each tool's `ARGUMENT_MAP`.

### Build Improvements
- **Box binary moved to `tools/`**: `tools/box` (Box 3.16.0) ships with the project. CI no longer needs `composer global require humbug/box`.

### Internal Improvements
- **ToolRegistry** extracted from `ToolAbstract` as injectable service. Centralizes tool name → class mapping, accelerable tools, exclude arguments, and script aliases.
- **JobRegistry** — maps job types to job classes, extends the pattern established by ToolRegistry.
- **Jobs use declarative `ARGUMENT_MAP`** instead of imperative `prepareCommand()`. Each job declares its arguments with type information (`value`, `boolean`, `paths`, `csv`, `repeat`, `key_value`).
- **Fake classes moved** from `src/` to `tests/Doubles/`.
- **Typed properties** added to remaining legacy classes (`ProcessExecutionAbstract`, `Process`).
- **Dead code removed**: `ToolAbstract::$errors`, `ProcessExecutionAbstract::printErrors()`, `ToolsTagIsEmptyException`, `ToolsTagIsNotFoundException`, debug code in `MultiProcessesExecution`, `TestToolTrait::$fakeExitCode`.
- **PHPUnit upgraded to 9.x**, `RetroCompatibilityAssertsTrait` eliminated.
- **CI reduced from 7 to 5 jobs** (removed PHP 7.0–7.3 matrices).
- **PHPStan level 8**: 0 errors on all new v3 code.

### Bug Fixes
- **`conf:check` updated for v3**: No longer reports "Tools tag missing" for v3 configs. Detects format automatically and delegates to the appropriate handler.
- **Undefined job references are warnings, not errors**: A typo in a flow's job list no longer blocks the entire flow — the missing job is skipped and a warning is shown.
- **Output indentation**: Job status lines (OK/KO/skipped) now indented with 2 spaces, aligned with error blocks.
- **`framedErrorBlock`**: References `githooks job` instead of the deprecated `githooks tool`.
