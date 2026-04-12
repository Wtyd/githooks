# Automating Hook Installation

Ensure every team member gets git hooks installed automatically when they run `composer install`.

## Add to composer.json

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

Now `composer install` and `composer update` will automatically install or update the hooks.

## PHP < 8.1

If your project needs to support PHP < 8.1, combine the `ComposerUpdater` script with hook installation:

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

## Verify

After setup, any team member can verify their hooks are correctly installed:

```bash
githooks status
```

This shows whether hooks are **synced**, **missing**, or **orphan**.

## Commit .githooks/

The `.githooks/` directory must be committed to version control. The scripts inside are universal — they call `githooks hook:run <event>` and resolve flows/jobs from the configuration at runtime. No regeneration needed when the config changes.
