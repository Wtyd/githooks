# CLI Reference

GitHooks is built on [Laravel Zero](https://laravel-zero.com/). Run `githooks list` to see all available commands.

All commands that read a configuration file accept `--config=path` to specify a custom path (absolute or relative).

## Commands

| Command | Description |
|---|---|
| [`githooks flow`](flow.md) | Run a flow (group of jobs). |
| [`githooks job`](job.md) | Run a single job. |
| [`githooks hook`](hook.md) | Install or remove git hooks. |
| [`githooks status`](status.md) | Show hook installation status. |
| [`githooks conf:init`](conf-init.md) | Generate configuration file. |
| [`githooks conf:check`](conf-check.md) | Validate configuration file. |
| [`githooks conf:migrate`](conf-migrate.md) | Migrate v2 config to v3 format. |
| [`githooks cache:clear`](cache-clear.md) | Clear QA tool cache files. |
| [`githooks system:info`](system-info.md) | Show CPU and process configuration. |
