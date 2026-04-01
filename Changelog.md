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
- `githooks flow <name>` — Run a flow by name.
- `githooks job <name>` — Run a single job by name.
- `githooks hook run <event>` — Run all flows/jobs associated with a git hook event (called by the universal hook script).
- `githooks hook` — Install git hooks via `core.hooksPath`.

### New Job Types
- **Custom**: Run any command via `script` key. Replaces the `script` tool and enables integration of non-native tools (e.g. `composer audit`, `eslint`, `php-cs-fixer`).

### New Configuration Classes
- `ConfigurationParser`, `ConfigurationResult`, `FlowConfiguration`, `HookConfiguration`, `JobConfiguration`, `OptionsConfiguration`, `ValidationResult` — replace the monolithic `ConfigurationFile`.

### New Execution Engine
- `FlowExecutor`, `FlowPreparer`, `FlowPlan`, `FlowResult`, `JobExecutor`, `JobResult` — replace `ToolsPreparer` and `ProcessExecution*` for v3 commands.
- `HookRunner`, `HookInstaller` — orchestrate hook resolution and installation.

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
