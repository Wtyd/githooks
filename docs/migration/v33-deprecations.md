# v3.3 deprecations

GitHooks 3.3 starts the deprecation cycle for the four legacy camelCase keys
that survived from v2 inside `jobs.<name>`. Both forms keep working in v3.3.x
with a runtime warning; the camelCase forms will be **removed in v4.0**.

The rest of the configuration surface (`flows.options`, `flows.<name>.options`,
all keys introduced from v3.0 onwards) was already in kebab-case and is not
affected.

## What changes

| camelCase (deprecated) | kebab-case (canonical) | Removed in |
|---|---|---|
| `executablePath` | `executable-path` | v4.0 |
| `otherArguments` | `other-arguments` | v4.0 |
| `ignoreErrorsOnExit` | `ignore-errors-on-exit` | v4.0 |
| `failFast` | `fail-fast` | v4.0 |

## How to migrate

Rename the four keys inside every `jobs.<name>` entry of your `githooks.php`.

Before:

```php
'jobs' => [
    'phpstan-src' => [
        'type'              => 'phpstan',
        'executablePath'    => 'vendor/bin/phpstan',
        'otherArguments'    => '--no-progress --ansi',
        'ignoreErrorsOnExit' => false,
        'failFast'          => true,
    ],
],
```

After:

```php
'jobs' => [
    'phpstan-src' => [
        'type'                  => 'phpstan',
        'executable-path'       => 'vendor/bin/phpstan',
        'other-arguments'       => '--no-progress --ansi',
        'ignore-errors-on-exit' => false,
        'fail-fast'             => true,
    ],
],
```

That is the entire migration. There is no semantic change; the runtime
behaviour is bit-by-bit identical.

## How to detect what needs migrating

Run any command that loads the config — `flow`, `flows`, `job`, `conf:check`,
`system:info`, `cache:clear` — and look at stderr:

```text
⚠ Deprecated: 'executablePath' is renamed to 'executable-path'. Will be removed in v4.0.
⚠ Deprecated: 'failFast' is renamed to 'fail-fast'. Will be removed in v4.0.
```

For machine processing, the structured JSON output exposes a dedicated block:

```bash
githooks flow qa --format=json | jq '.deprecations'
```

```json
[
  {
    "job": "phpstan-src",
    "oldKey": "executablePath",
    "newKey": "executable-path",
    "removalVersion": "v4.0",
    "kind": "config-key-rename"
  }
]
```

The same block appears in SARIF output under `runs[0].properties.deprecations`.

## Conflict between both forms

If the same job declares both forms simultaneously (for instance after a
copy-paste from a guide on top of an existing config), the parser aborts the
job with an error:

```text
✗ Job 'phpstan-src': conflicting keys 'executablePath' and 'executable-path'.
  Use only one (kebab-case form is canonical).
```

Pick the kebab-case form and remove the camelCase entry.

## Roadmap

| Step | Version | Behaviour |
|---|---|---|
| 1 | **v3.3** | Both forms accepted. Deprecation warning per camelCase key. |
| 2 | v3.x | `conf:migrate` rewrites camelCase to kebab-case automatically. |
| 3 | v4.0 | camelCase support removed. Only kebab-case accepted. |

The deprecation warning is intentionally not silenceable. Migrating is the
only way to stop seeing it; `conf:migrate` (step 2) will make that mechanical
once it ships.
