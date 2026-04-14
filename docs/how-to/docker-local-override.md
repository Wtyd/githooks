# Docker & Local Override

Run GitHooks with Docker, Laravel Sail, or any remote environment — without modifying the shared configuration.

## The problem

Your team has mixed environments: some developers run PHP natively, others use Docker, others use Laravel Sail. The shared `githooks.php` shouldn't contain environment-specific paths or commands.

## The solution

Two features work together:

1. **`executable-prefix`** — a command prepended to all job executables.
2. **`githooks.local.php`** — a local override file merged over the shared config.

### Step 1: Keep the shared config clean

```php
// githooks.php (committed to git)
<?php
return [
    'flows' => [
        'options' => ['processes' => 2],
        'qa' => ['jobs' => ['phpstan_src', 'phpcs_src', 'phpmd_src']],
    ],

    'jobs' => [
        'phpstan_src' => [
            'type'  => 'phpstan',
            'paths' => ['src'],
            'level' => 8,
        ],
        'phpcs_src' => [
            'type'     => 'phpcs',
            'paths'    => ['src'],
            'standard' => 'PSR12',
        ],
        'phpmd_src' => [
            'type'  => 'phpmd',
            'paths' => ['src'],
            'rules' => 'cleancode,codesize,naming,unusedcode',
        ],
    ],
];
```

### Step 2: Each developer creates their local override

**Docker user** — `githooks.local.php`:

```php
<?php
return [
    'flows' => [
        'options' => [
            'executable-prefix' => 'docker exec -i app',
        ],
    ],
];
```

**Laravel Sail user** — `githooks.local.php`:

```php
<?php
return [
    'flows' => [
        'options' => [
            'executable-prefix' => './vendor/bin/sail exec laravel.test',
        ],
    ],
];
```

**Native PHP user** — no file needed. Or an empty override:

```php
<?php
return [];
```

### Step 3: Add to .gitignore

```
githooks.local.php
```

## How it works

- GitHooks looks for `githooks.local.php` in the same directory as `githooks.php`.
- If found, it merges the local config over the main config using `array_replace_recursive`.
- The `executable-prefix` is prepended to every job's `executablePath` automatically.

So `vendor/bin/phpstan analyse src` becomes `docker exec -i app vendor/bin/phpstan analyse src`.

## Excluding specific jobs from the prefix

Some jobs may need to run locally even when a global prefix is set (e.g., a local npm script):

```php
// githooks.php
'eslint_src' => [
    'type'              => 'custom',
    'script'            => 'npx eslint src/',
    'executable-prefix' => null,  // Explicit opt-out: always runs locally
],
```

Setting `executable-prefix` to `null` or `''` on a job overrides the global prefix for that job.

## Verify

Use `--dry-run` to see the actual commands with the prefix applied:

```bash
githooks flow qa --dry-run
```

## See also

- [Configuration: Options — executable-prefix](../configuration/options.md#executable-prefix)
- [Configuration File — Local Override](../configuration/file.md#local-override-githookslocalphp)
