# Running Your First Hook

## Install the hooks

```bash
alfil hook
```

This command:

1. Creates a `.githooks/` directory with universal hook scripts for each event defined in your configuration.
2. Runs `git config core.hooksPath .githooks` so Git uses these scripts.

!!! important
    The `.githooks/` directory should be **committed to version control**. The scripts are universal — they never need to be regenerated after config changes.

## Test it

Make a commit and watch Alfil run your configured QA tools:

```bash
git add .
git commit -m "test: verify alfil is working"
```

If all checks pass, the commit proceeds. If any check fails, the commit is blocked and you see the errors.

## Run manually

You don't need to commit to run your QA tools. Use the CLI directly:

```bash
alfil flow qa                          # Run a flow (group of jobs)
alfil flow qa --fast                   # Only analyze staged files
alfil flow qa --only-jobs=phpstan_src  # Run specific jobs from a flow
alfil flow qa --dry-run                # Show commands without executing
alfil job phpstan_src                  # Run a single job
alfil job phpstan_src --format=json    # JSON output for CI and AI integration
alfil job phpunit_all -- --filter=testFoo          # Pass extra args to the tool
```

## Check hook status

```bash
alfil status
```

Shows which hooks are installed and whether they're synchronized with your configuration:

- **synced** — installed and matches configuration.
- **missing** — configured but not installed (run `alfil hook` to fix).
- **orphan** — installed but not in configuration.

## Automate installation

Add the hook installation to your `composer.json` so every team member gets hooks automatically:

```json
"scripts": {
    "post-update-cmd": [
        "vendor/bin/alfil hook"
    ],
    "post-install-cmd": [
        "vendor/bin/alfil hook"
    ]
}
```

!!! note "PHP < 8.1"
    If you already have the `ComposerUpdater` script for PHP < 8.1, combine both:
    ```json
    "scripts": {
        "post-update-cmd": [
            "Wtyd\\Alfil\\Utils\\ComposerUpdater::phpOldVersions",
            "vendor/bin/alfil hook"
        ],
        "post-install-cmd": [
            "Wtyd\\Alfil\\Utils\\ComposerUpdater::phpOldVersions",
            "vendor/bin/alfil hook"
        ]
    }
    ```

## Legacy mode (Git < 2.9)

If your environment doesn't support `core.hooksPath`, use legacy mode:

```bash
alfil hook --legacy
```

This copies hook scripts directly to `.git/hooks/` instead.

## What's next?

You have a working setup. From here you can:

- [Learn the configuration in depth](../configuration/index.md) — hooks, flows, jobs, and all keywords.
- [Explore the supported tools](../tools/index.md) — each tool's options and examples.
- [Set up parallel execution](../how-to/parallel-execution.md) — speed up your QA runs.
- [Add conditional hooks](../how-to/conditional-hooks.md) — run different tools on different branches.
