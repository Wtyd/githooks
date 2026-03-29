# Changelog

All notable changes to this project will be documented in this file.

## [2.8.0] - Unreleased

### New Tools
- **PHPUnit**: New natively supported tool for running PHPUnit tests. Supported options: `group`, `exclude-group`, `filter`, `configuration`, `log-junit`.
- **Psalm**: New natively supported tool for Psalm static analysis. Supported options: `config`, `memory-limit`, `threads`, `no-diff`, `output-format`, `plugin`, `use-baseline`, `report`, `paths`.
- **Script**: New generic tool type that allows integrating any QA tool not natively supported by GitHooks (e.g. php-cs-fixer, infection, pdepend). Only requires `executablePath` (mandatory) and `otherArguments`. Supports a `name` key for custom aliasing (e.g. `name: php-cs-fixer`) — use the custom name in the `Tools` array and CLI instead of `script`.

### Features
- **PHP 8.5 support**: Fully compatible with PHP 8.5.
- **Per-tool execution mode override**: Each tool can now have its own `execution: fast` or `execution: full` setting, overriding the global Options. Priority order: CLI argument > per-tool setting > global Options > default (`full`). Non-accelerable tools configured with `fast` show a warning and fall back to `full`.
- **Per-tool `failFast` option**: New boolean option available on all tools. When `true`, if the tool fails, all remaining tools are skipped (shown as "⏩ toolName (skipped by failFast)"). Takes priority over `ignoreErrorsOnExit` when both are set.
- **Phpcbf auto-staging**: When phpcbf fixes files during a pre-commit hook run, they are automatically re-staged to git. Deleted files are excluded from re-staging.
- **Psalm fast mode**: Psalm added to the list of accelerable tools that support `fast` execution mode.
- **Custom config path (`-c|--config`)**: Both `tool` and `conf:check` commands now accept a custom configuration file path, supporting both absolute and relative paths.
- **`conf:check` improvements**: Now shows the configuration file path in output.

### New Tool-Specific Options
- **Phpstan**: `error-format`, `no-progress`, `clear-result-cache`.
- **Phpcs/Phpcbf**: `cache`, `no-cache`, `report`, `parallel`.
- **Phpmd**: `cache` (PHPMD 2.13.0+), `cache-file`, `cache-strategy`, `suffixes`, `baseline-file`.
- **Phpcpd**: `min-lines`, `min-tokens`.
- **Parallel-lint**: `jobs` (number of parallel jobs, `-j` flag).

### Output Improvements
- Emoji indicators for tool results: ✔️ success, ❌ error, ⚠️ warning.
- Better time formatting using ms/s/m units instead of raw seconds.
- Error output displayed in a framed block with line limiting (first 20 lines + last 3 lines, with omitted line count).
- Summary line: "Results: X/Y passed" with per-tool breakdown for failures.

### Bug Fixes
- Fixed phpcbf handling of fix-applied exit code (exit code 1) in single tool execution.
- Exclude deleted files when re-staging after phpcbf fix.
- Fixed process command parsing for proper shell escaping with paths containing spaces.
- Improved absolute path detection for Windows compatibility.

### Internal Refactoring
- Adapt the pipelines and dependency ranges to PHP 8.5.
- General code cleanup: removed dead code, fixed class naming, simplified internal APIs.
