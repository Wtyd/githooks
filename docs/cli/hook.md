# githooks hook

Install or remove git hooks.

## Install hooks

```
githooks hook [options]
```

For each event defined in the [`hooks` section of the configuration](../configuration/hooks.md), GitHooks:

1. Creates a `.githooks/<event>` script file.
2. Runs `git config core.hooksPath .githooks`.

The generated scripts are universal and identical — each one calls `githooks hook:run <event>`, which resolves the event to its configured flows/jobs. The command prefix is taken from [`hooks.command`](../configuration/hooks.md) in the config (defaults to `php vendor/bin/githooks`).

When you install with `--config=<file>`, the scripts also carry `--config` with that file's path **relative to the repository root**, so every trigger runs the configuration the hooks were installed from — not whatever `githooks.php` the current directory resolves to. Git runs hooks from the top level of the working tree, so the relative path works in every clone that commits `.githooks/`. A file outside the repository can only be baked as an absolute path; the install warns that other clones will not have it.

```bash
githooks hook                  # Install all configured hooks
githooks hook --config=qa/githooks.php   # Hooks run qa/githooks.php on every trigger
githooks hook --legacy         # Install pre-commit in .git/hooks/ (Git < 2.9)
githooks hook pre-push --legacy  # Install specific hook in .git/hooks/
```

!!! tip
    The `.githooks/` directory should be committed to version control. The scripts are universal — they never need to be regenerated after config changes, only if you move or rename the file they were installed with via `--config`.

### Legacy mode

The `--legacy` flag installs hooks by copying a script to `.git/hooks/`. This is needed for environments with Git < 2.9 which does not support `core.hooksPath`. You can also pass a custom script file:

```bash
githooks hook pre-commit MyCustomPrecommit.php --legacy
```

## Remove hooks

```
githooks hook:clean [options]
```

```bash
githooks hook:clean                     # Remove .githooks/ + unset core.hooksPath
githooks hook:clean --legacy            # Remove pre-commit from .git/hooks/
githooks hook:clean pre-push --legacy   # Remove specific hook from .git/hooks/
```

Without `--legacy`, removes the entire `.githooks/` directory and unsets `core.hooksPath`. With `--legacy`, removes individual hooks from `.git/hooks/`.

## Internal: hook:run

```
githooks hook:run <event> [--config=<file>]
```

Executes all flows and jobs associated with a git hook event. This is the command that the universal hook script calls internally — you normally don't need to run it manually.

For `pre-commit` events, fast mode is activated automatically.

## See also

- [Configuration: Hooks](../configuration/hooks.md)
- [`githooks status`](status.md) — check hook installation status.
- [Getting Started: Running Your First Hook](../getting-started/first-hook.md)
