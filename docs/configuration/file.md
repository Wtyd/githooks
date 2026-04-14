# Configuration File

## File format

PHP is the primary format. The file must return a PHP array:

```php
<?php
return [
    'hooks' => [ ... ],
    'flows' => [ ... ],
    'jobs'  => [ ... ],
];
```

YAML (`githooks.yml`) is still supported but **deprecated since v3.0** and will be removed in v4.0.

## Search paths

GitHooks searches for the configuration file in this order:

1. `./githooks.php`
2. `./qa/githooks.php`
3. `./githooks.yml` (deprecated)
4. `./qa/githooks.yml` (deprecated)

Use `--config=path` on any command to specify a custom path.

## Local override (`githooks.local.php`)

GitHooks looks for a `githooks.local.php` file in the same location as `githooks.php`. If found, its contents are merged over the main configuration using `array_replace_recursive`.

This allows each developer to customize their environment without modifying the shared configuration. Add `githooks.local.php` to your `.gitignore`.

```php
// githooks.local.php — Docker user
<?php
return [
    'flows' => [
        'options' => [
            'executable-prefix' => 'docker exec -i app',
        ],
    ],
];
```

```php
// githooks.local.php — Laravel Sail user
<?php
return [
    'flows' => [
        'options' => [
            'executable-prefix' => './vendor/bin/sail exec laravel.test',
        ],
    ],
];
```

Developers with native PHP don't need a local file at all. The shared `githooks.php` stays clean, with no environment-specific references.

See [Options: executable-prefix](options.md#executable-prefix) for details on how the prefix is applied.
See [How-To: Docker & Local Override](../how-to/docker-local-override.md) for practical examples.

## Generate

```bash
githooks conf:init             # Interactive setup
githooks conf:init -n          # Copy template (non-interactive)
githooks conf:init --legacy    # Generate v2 format (deprecated)
```

See [`conf:init`](../cli/conf-init.md) for details.

## Validate

```bash
githooks conf:check
```

Checks:

- File exists in the expected location.
- Structure is correct (hooks/flows/jobs).
- Job types are supported.
- Argument types are valid (paths must be array, rules must be string, etc.).
- Flow and hook references point to existing jobs/flows.
- Hook names are valid git events.
- Unknown keys are detected (warnings).
- Executables, paths, and config files exist on disk (deep validation).

See [`conf:check`](../cli/conf-check.md) for details.

## Migrate from v2

```bash
githooks conf:migrate
```

Converts a v2 configuration (Options/Tools format) to v3 (hooks/flows/jobs). Creates a backup (`.v2.bak`) of the original file.

See [Migration: v2 to v3](../migration/v2-to-v3.md) for a complete guide.
