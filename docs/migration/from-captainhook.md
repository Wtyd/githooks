# From CaptainHook

## Concept mapping

| CaptainHook | GitHooks |
|---|---|
| `captainhook.json` | `githooks.php` |
| Hook events | `hooks` section |
| Actions (PHP classes) | `jobs` (declarative config) |
| Conditions (PHP classes) | `only-on`, `exclude-on`, `only-files`, `exclude-files` |
| `options` in actions | Type-specific keywords in jobs |

## Key difference

CaptainHook uses **PHP classes** as actions — you write or reference a class that implements an interface. GitHooks uses **declarative configuration** — you specify the tool type and its arguments, and GitHooks builds the shell command.

## Example migration

**CaptainHook (`captainhook.json`):**
```json
{
  "pre-commit": {
    "actions": [
      {
        "action": "\\CaptainHook\\App\\Hook\\Composer\\Action\\CheckLockFile"
      },
      {
        "action": "phpstan analyse src --level 8"
      },
      {
        "action": "phpcs --standard=PSR12 src"
      }
    ]
  },
  "pre-push": {
    "actions": [
      {
        "action": "vendor/bin/phpunit"
      }
    ]
  }
}
```

**GitHooks (`githooks.php`):**
```php
<?php
return [
    'hooks' => [
        'pre-commit' => ['qa'],
        'pre-push'   => ['test'],
    ],

    'flows' => [
        'qa'   => ['jobs' => ['composer_audit', 'phpstan_src', 'phpcs_src']],
        'test' => ['jobs' => ['phpunit_all']],
    ],

    'jobs' => [
        'composer_audit' => [
            'type'   => 'custom',
            'script' => 'composer audit',
        ],
        'phpstan_src' => [
            'type'  => 'phpstan',
            'level' => 8,
            'paths' => ['src'],
        ],
        'phpcs_src' => [
            'type'     => 'phpcs',
            'standard' => 'PSR12',
            'paths'    => ['src'],
        ],
        'phpunit_all' => [
            'type'   => 'phpunit',
            'config' => 'phpunit.xml',
        ],
    ],
];
```

## Key differences

| Feature | CaptainHook | GitHooks |
|---|---|---|
| **Distribution** | Composer dependency | `.phar` standalone (isolated) |
| **Config format** | JSON | PHP |
| **Actions** | PHP classes or shell commands | Declarative config (type + keywords) |
| **Conditions** | PHP condition classes | Glob patterns (`only-on`, `only-files`) |
| **Parallel execution** | Not built-in | `processes` with thread budget |
| **Fast mode** | Not built-in | `--fast` + `--fast-branch` |
| **Output** | Text | Text, JSON, JUnit |
| **Job inheritance** | Not supported | `extends` |

## Steps

1. Create `githooks.php` based on your `captainhook.json` (see example above).
2. Install GitHooks: `composer require --dev wtyd/githooks`
3. Install hooks: `githooks hook`
4. Validate: `githooks conf:check`
5. Remove CaptainHook: `composer remove --dev captainhook/captainhook`
6. Delete `captainhook.json`.
