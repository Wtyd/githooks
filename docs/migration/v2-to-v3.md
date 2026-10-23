# Upgrading from v2

Alfil 4.0 (formerly GitHooks) reads only the v3 shape — `hooks` / `flows` / `jobs` in a PHP file. Everything the 3.x line had marked "removed in v4.0" is gone: the `tool` command, YAML configuration files, the `Options` / `Tools` shape, the camelCase job keys and `conf:migrate` itself. A v2 configuration is rejected before anything runs, by every command:

```text
v2 configuration format (Options/Tools) is not supported since 4.0: /srv/app/githooks.php. Migrate it with GitHooks 3.8 ('githooks conf:migrate') before upgrading.
```

Migrate with the last 3.x release first, then upgrade.

## Migrate with 3.8, then upgrade

```bash
composer require --dev wtyd/githooks:^3.8
vendor/bin/githooks conf:migrate     # Options/Tools (PHP or YAML) → hooks/flows/jobs; backup in .v2.bak
vendor/bin/githooks conf:check       # must report a valid v3 file
composer remove --dev wtyd/githooks
composer require --dev wtyd/alfil    # 4.0: the package and binary are called alfil
vendor/bin/alfil hook                # reinstall the hooks for the new file
```

The last three steps are the ordinary 3.x → 4.0 upgrade; see [Upgrading from 3.x to 4.0](to-4-0.md) for the rest of what changes with the new name.

`conf:migrate` (3.8) converts YAML to PHP, renames the camelCase keys to kebab-case, drops `usePhpcsConfiguration` and generates a `qa` flow with every original tool as a job. Review the result before upgrading: rename flows and jobs, add the hook mappings (the migrated file does not configure hooks), split tools into flows. Delete the old `githooks.yml` once the PHP file is in place — a `.yml` left behind in the project root or in `qa/` is reported as unsupported, not ignored.

## What changed between v2 and v3

| Aspect | v2 | v3 (and 4.0) |
|---|---|---|
| **Config format** | YAML (`githooks.yml`) | PHP only (`githooks.php`; `alfil.php` since 4.0) |
| **Config structure** | `Options` + `Tools` | `hooks` + `flows` + `jobs` |
| **Job keys** | camelCase (`executablePath`, …) | kebab-case (`executable-path`, …) |
| **CLI** | `githooks tool <name>` | `githooks flow <name>` / `githooks job <name>` (`alfil …` since 4.0) |
| **Hook install** | Copies script to `.git/hooks/` | Creates `.githooks/` + `core.hooksPath` |
| **Multiple hooks** | Only `pre-commit` | All git events (`pre-commit`, `pre-push`, `commit-msg`, etc.) |
| **Conditional execution** | Not supported | `only-on`, `exclude-on`, `only-files`, `exclude-files` |
| **Job inheritance** | Not supported | `extends` |
| **Execution modes** | `full`, `fast` | `full`, `fast`, `fast-branch`, `fast-dirty` |
| **Parallel** | `processes` option | `processes` + thread budget distribution |
| **Output** | Text only | `text`, `json`, `junit`, `sarif`, `codeclimate` |
| **security-checker** | Native type | Removed. Use `custom` with `composer audit`. |
| **usePhpcsConfiguration** | Supported | Removed. Use explicit keywords. |

## Manual migration

If you would rather not install 3.8, write the v3 file by hand.

### Step 1: Convert the structure

**v2:**
```yaml
Options:
  execution: full
  processes: 2

Tools:
  - phpstan
  - phpcs
  - phpmd

phpstan:
  config: phpstan.neon
  level: 8
  paths:
    - src

phpcs:
  standard: PSR12
  paths:
    - src

phpmd:
  rules: cleancode,codesize
  paths:
    - src
```

**v3:**
```php
<?php
return [
    'hooks' => [
        'pre-commit' => ['qa'],
    ],

    'flows' => [
        'options' => ['processes' => 2],
        'qa' => ['jobs' => ['phpstan_src', 'phpcs_src', 'phpmd_src']],
    ],

    'jobs' => [
        'phpstan_src' => [
            'type'   => 'phpstan',
            'config' => 'phpstan.neon',
            'level'  => 8,
            'paths'  => ['src'],
        ],
        'phpcs_src' => [
            'type'     => 'phpcs',
            'standard' => 'PSR12',
            'paths'    => ['src'],
        ],
        'phpmd_src' => [
            'type'  => 'phpmd',
            'rules' => 'cleancode,codesize',
            'paths' => ['src'],
        ],
    ],
];
```

### Step 2: Rename the camelCase keys

The four keys v3 inherited from v2 were deprecated in 3.3 and are rejected in 4.0. A job declaring one is refused with the kebab-case key named in the error:

```text
Job 'phpstan_src': key 'executablePath' was removed in 4.0. Use 'executable-path'.
```

| camelCase (removed) | kebab-case |
|---|---|
| `executablePath` | `executable-path` |
| `otherArguments` | `other-arguments` |
| `ignoreErrorsOnExit` | `ignore-errors-on-exit` |
| `failFast` | `fail-fast` |

### Step 3: Replace security-checker

**v2:**
```yaml
Tools:
  - security-checker
```

**v3:**
```php
'composer_audit' => [
    'type'   => 'custom',
    'script' => 'composer audit',
],
```

### Step 4: Reinstall hooks

```bash
vendor/bin/alfil hook:clean --legacy   # remove old hooks from .git/hooks/
vendor/bin/alfil hook                  # install new hooks via .githooks/
```

### Step 5: Update composer.json scripts

Replace the v2 hook command with the v3 equivalent:

```json
"scripts": {
    "post-update-cmd": ["vendor/bin/alfil hook"],
    "post-install-cmd": ["vendor/bin/alfil hook"]
}
```

### Step 6: Validate

```bash
vendor/bin/alfil conf:check
```
