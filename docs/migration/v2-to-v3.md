# From v2 to v3

## What changed

| Aspect | v2 | v3 |
|---|---|---|
| **Config format** | YAML preferred (`githooks.yml`) | PHP preferred (`githooks.php`). YAML deprecated. |
| **Config structure** | `Options` + `Tools` | `hooks` + `flows` + `jobs` |
| **CLI** | `githooks tool <name>` | `githooks flow <name>` / `githooks job <name>` |
| **Hook install** | Copies script to `.git/hooks/` | Creates `.githooks/` + `core.hooksPath` |
| **Multiple hooks** | Only `pre-commit` | All git events (`pre-commit`, `pre-push`, `commit-msg`, etc.) |
| **Conditional execution** | Not supported | `only-on`, `exclude-on`, `only-files`, `exclude-files` |
| **Job inheritance** | Not supported | `extends` |
| **Execution modes** | `full`, `fast` | `full`, `fast`, `fast-branch` |
| **Parallel** | `processes` option | `processes` + thread budget distribution |
| **Output** | Text only | `text`, `json`, `junit` |
| **security-checker** | Native type | Removed. Use `custom` with `composer audit`. |
| **usePhpcsConfiguration** | Supported | Removed. Use explicit keywords. |

## Automatic migration

```bash
githooks conf:migrate
```

This command:

1. Creates a backup (`.v2.bak`) of the original file.
2. Converts YAML to PHP if needed (removes the `.yml` file).
3. Converts `script` tool to `custom` job type.
4. Drops `usePhpcsConfiguration`.
5. Generates a v3 file with a `qa` flow containing all original tools as jobs.

!!! warning
    Review the generated file after migration. You may want to:

    - Rename flows and jobs to meaningful names.
    - Add hook mappings (the migrated file does not configure hooks automatically).
    - Split tools into multiple flows.
    - Add conditions for different hooks.

## Manual migration

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

### Step 2: Replace security-checker

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

### Step 3: Reinstall hooks

```bash
githooks hook:clean --legacy   # remove old hooks from .git/hooks/
githooks hook                  # install new hooks via .githooks/
```

### Step 4: Update composer.json scripts

Replace the v2 hook command with the v3 equivalent:

```json
"scripts": {
    "post-update-cmd": ["vendor/bin/githooks hook"],
    "post-install-cmd": ["vendor/bin/githooks hook"]
}
```

### Step 5: Validate

```bash
githooks conf:check
```
