# Running Your First Hook

## Install the hooks

```bash
githooks hook
```

This command:

1. Creates a `.githooks/` directory with universal hook scripts for each event defined in your configuration.
2. Runs `git config core.hooksPath .githooks` so Git uses these scripts.

!!! important
    The `.githooks/` directory should be **committed to version control**. The scripts are universal — they never need to be regenerated after config changes.

## Test it

Make a commit and watch GitHooks run your configured QA tools:

```bash
git add .
git commit -m "test: verify githooks is working"
```

If all checks pass, the commit proceeds. If any check fails, the commit is blocked and you see the errors.

## Run manually

You don't need to commit to run your QA tools. Use the CLI directly:

```bash
githooks flow qa                          # Run a flow (group of jobs)
githooks flow qa --fast                   # Only analyze staged files
githooks flow qa --only-jobs=phpstan_src  # Run specific jobs from a flow
githooks flow qa --dry-run                # Show commands without executing
githooks job phpstan_src                  # Run a single job
githooks job phpstan_src --format=json    # JSON output for CI
```

## Check hook status

```bash
githooks status
```

Shows which hooks are installed and whether they're synchronized with your configuration:

- **synced** — installed and matches configuration.
- **missing** — configured but not installed (run `githooks hook` to fix).
- **orphan** — installed but not in configuration.

## Automate installation

Add the hook installation to your `composer.json` so every team member gets hooks automatically:

```json
"scripts": {
    "post-update-cmd": [
        "vendor/bin/githooks hook"
    ],
    "post-install-cmd": [
        "vendor/bin/githooks hook"
    ]
}
```

!!! note "PHP < 8.1"
    If you already have the `ComposerUpdater` script for PHP < 8.1, combine both:
    ```json
    "scripts": {
        "post-update-cmd": [
            "Wtyd\\GitHooks\\Utils\\ComposerUpdater::phpOldVersions",
            "vendor/bin/githooks hook"
        ],
        "post-install-cmd": [
            "Wtyd\\GitHooks\\Utils\\ComposerUpdater::phpOldVersions",
            "vendor/bin/githooks hook"
        ]
    }
    ```

## Legacy mode (Git < 2.9)

If your environment doesn't support `core.hooksPath`, use legacy mode:

```bash
githooks hook --legacy
```

This copies hook scripts directly to `.git/hooks/` instead.

## What's next?

You have a working setup. From here you can:

- [Learn the configuration in depth](../configuration/index.md) — hooks, flows, jobs, and all keywords.
- [Explore the supported tools](../tools/index.md) — each tool's options and examples.
- [Set up parallel execution](../how-to/parallel-execution.md) — speed up your QA runs.
- [Add conditional hooks](../how-to/conditional-hooks.md) — run different tools on different branches.
