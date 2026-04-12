# Conditional Hooks

Run different tools depending on the branch, staged files, or both.

## Run only on specific branches

```php
'hooks' => [
    'pre-push' => [
        ['flow' => 'full', 'only-on' => ['main', 'develop']],
    ],
],
```

The `full` flow only runs when pushing from `main` or `develop`.

## Skip on feature branches

```php
'hooks' => [
    'pre-commit' => [
        'qa',                                                    // always runs
        ['flow' => 'heavy', 'exclude-on' => ['GH-*', 'temp/*']], // skip on these
    ],
],
```

## Run only when PHP files are staged

```php
'hooks' => [
    'pre-commit' => [
        ['flow' => 'php-qa', 'only-files' => ['**/*.php']],
        ['job' => 'eslint', 'only-files' => ['resources/js/**']],
    ],
],
```

The PHP QA flow only runs if PHP files are staged. The ESLint job only runs if JavaScript files are staged.

## Combine conditions

Conditions are **AND-ed** — all must be satisfied:

```php
// Only on release branches AND only when PHP files are staged
['flow' => 'deploy', 'only-on' => ['release/*'], 'only-files' => ['**/*.php']],
```

## Exclude prevails over include

When a branch or file matches both `only-*` and `exclude-*`, it is **excluded**:

```php
// All release branches EXCEPT beta releases
['flow' => 'deploy', 'only-on' => ['release/*'], 'exclude-on' => ['release/beta-*']],

// All PHP files EXCEPT generated ones
['job' => 'phpcs', 'only-files' => ['src/**/*.php'], 'exclude-files' => ['src/Generated/**']],
```

## Pattern syntax

All patterns use glob syntax. For file patterns, `*` does not cross directories — use `**` for recursive matching:

| Pattern | Matches |
|---|---|
| `src/*.php` | `src/User.php` but not `src/Models/User.php` |
| `src/**/*.php` | `src/User.php`, `src/Models/User.php`, `src/A/B/C.php` |
| `release/*` | `release/v2.0` (branch patterns: `*` crosses `/`) |

See [Configuration: Hooks](../configuration/hooks.md#pattern-syntax) for the full pattern reference.
