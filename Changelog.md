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
- `githooks flow <name>` — Run a flow by name. Supports `--fail-fast`, `--processes=N`, `--exclude-jobs=a,b`, `--format=json|junit`, `--fast`, `--monitor`, `--config=path`.
- `githooks job <name>` — Run a single job by name. Supports `--format=json|junit`, `--fast`, `--config=path`.
- `githooks hook:run <event>` — Run all flows/jobs associated with a git hook event (called by the universal hook script).
- `githooks hook` — Install git hooks via `core.hooksPath` + `.githooks/` directory. Reads hooks section from config. `--legacy` flag for `.git/hooks/` mode (Git < 2.9).
- `githooks hook:clean` — Remove installed hooks. Default removes `.githooks/` + unsets `core.hooksPath`. `--legacy` flag removes individual hooks from `.git/hooks/`.
- `githooks status` — Show installed hooks, their sync state with config (synced/missing/orphan), and target flows/jobs.
- `githooks system:info` — Show detected CPUs and current `processes` configuration with budget warning.
- `githooks conf:init` — Generate a new `githooks.php` from the distribution template. `--legacy` flag generates v2 format.
- `githooks conf:migrate` — Migrate v2 configuration (Options/Tools) to v3 format (hooks/flows/jobs) with automatic backup.
- `githooks conf:check` — Updated for v3: shows Options, Hooks, Flows, and Jobs tables with the full command each job will execute. All commands support `--config=path` to specify a custom configuration file.

### New Job Types
- **Custom**: Run any command via `script` key. Replaces the `script` tool and enables integration of non-native tools (e.g. `composer audit`, `eslint`, `php-cs-fixer`).

### New Configuration Classes
- `ConfigurationParser`, `ConfigurationResult`, `FlowConfiguration`, `HookConfiguration`, `JobConfiguration`, `OptionsConfiguration`, `ValidationResult`, `ConfigurationMigrator` — replace the monolithic `ConfigurationFile`.

### New Execution Engine
- `FlowExecutor`, `FlowPreparer`, `FlowPlan`, `FlowResult`, `JobExecutor`, `JobResult` — replace `ToolsPreparer` and `ProcessExecution*` for v3 commands.
- `HookRunner`, `HookInstaller`, `HookStatusInspector`, `HookStatusReport`, `HookEventStatus` — orchestrate hook resolution, installation, and status inspection.
- `ExecutionContext` — propagates fast mode and staged files to custom jobs.
- `ThreadBudgetAllocator`, `ThreadBudgetPlan`, `ThreadCapability` — distribute CPU cores across parallel jobs respecting each tool's threading capabilities.
- `JsonResultFormatter`, `JunitResultFormatter`, `TextOutputHandler`, `NullOutputHandler` — structured output rendering.

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
- **CLI options `--fail-fast`, `--processes`, `--exclude-jobs` now work**: Were defined in `flow` command signature but not read in `handle()`. Now properly override config values.
- **`githooks hook` installs v3 hooks**: Was still copying legacy `default.php` to `.git/hooks/`. Now uses `HookInstaller` with `core.hooksPath` + `.githooks/`.
- **`conf:init` finds distribution file**: Was looking for non-existent `githooks.v3.dist.php`. Now resolves `qa/githooks.dist.php` correctly.
- **`-c` shortcut removed**: Format `{-c|--config=}` caused fatal error in Symfony Console on PHP 8.1+. Replaced with `--config=` (long form only).
- **Job argument type validation**: `conf:check` now validates argument types (`paths` must be array, `rules` must be string, etc.) and warns about unknown keys in custom jobs.
- **Config file not found shows friendly error**: `ConfigurationParser` now checks `file_exists()` before `require`, throwing `ConfigurationFileNotFoundException` instead of a PHP stack trace.
- **No confusing "undefined job" warning for type errors**: Jobs with invalid types no longer trigger a secondary "flow references undefined job" warning.
- **Unknown `--format` values show warning**: Invalid format (e.g. `--format=csv`) now warns and falls back to text instead of silent fallback.
- **`conf:migrate` with empty config shows errors**: Empty `return []` now shows validation errors instead of misleading "already in v3 format".
- **PHP 8.1+ compatibility**: Removed unused `MocksApplicationServices` trait that was deleted in modern Laravel.
