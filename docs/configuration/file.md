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

YAML (`githooks.yml`) is **not supported since 4.0**: a `.yml` passed with `--config`, or left behind in the project root or `qa/`, is rejected with a message pointing at [the migration](../migration/v2-to-v3.md). The same goes for the v2 `Options` / `Tools` shape in a PHP file.

## Search paths

Alfil searches for the configuration file in this order:

1. `./alfil.php`
2. `./qa/alfil.php`
3. `./githooks.php` — the pre-4.0 name, deprecated (removed in 5.0)
4. `./qa/githooks.php` — same

A `githooks.php` found this way works as before and the run reports a `config-file-rename` deprecation; rename it to `alfil.php` (and its `githooks.local.php`, if any, to `alfil.local.php`). The new name wins over the location: `qa/alfil.php` is used before a root `githooks.php`, and a `githooks.php` left next to an `alfil.php` is reported as ignored. A `--config` path is used as given and never flagged, whatever its name.

Use `--config=path` on any command to specify a custom path. Without one, and with no file in either location, every command stops with `Configuration file not found. Expected alfil.php in the project root or in qa/ (or pass --config=PATH).`

## Local override (`alfil.local.php`)

Alfil looks for an `alfil.local.php` file in the same location as `alfil.php`. If found, its contents are merged over the main configuration using `array_replace_recursive`. The override pairs with the main file by name (`githooks.php` pairs with `githooks.local.php`); an override whose name does not match its main file is not merged, and the run says which file to rename.

This allows each developer to customize their environment without modifying the shared configuration. Add `alfil.local.php` to your `.gitignore`.

```php
// alfil.local.php — Docker user
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
// alfil.local.php — Laravel Sail user
<?php
return [
    'flows' => [
        'options' => [
            'executable-prefix' => './vendor/bin/sail exec laravel.test',
        ],
    ],
];
```

Developers with native PHP don't need a local file at all. The shared `alfil.php` stays clean, with no environment-specific references.

See [Options: executable-prefix](options.md#executable-prefix) for details on how the prefix is applied.
See [How-To: Docker & Local Override](../how-to/docker-local-override.md) for practical examples.

## Generate

```bash
alfil conf:init             # Interactive setup
alfil conf:init -n          # Copy template (non-interactive)
```

See [`conf:init`](../cli/conf-init.md) for details.

## Validate

```bash
alfil conf:check
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

4.0 has no migrator: a v2 configuration (`Options` / `Tools`, PHP or YAML) is migrated with GitHooks 3.8 (`conf:migrate`) before upgrading, or rewritten by hand. See [Upgrading from v2](../migration/v2-to-v3.md).
