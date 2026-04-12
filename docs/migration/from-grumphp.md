# From GrumPHP

## Concept mapping

| GrumPHP | GitHooks |
|---|---|
| `grumphp.yml` | `githooks.php` |
| `tasks` | `jobs` |
| Task name (e.g. `phpstan`) | Job type (`'type' => 'phpstan'`) |
| `task.metadata.priority` | Job order in the flow's `jobs` array |
| `parameters.tasks` | `flows` + `jobs` |
| `parameters.process_timeout` | Not needed (tool-level) |
| `parameters.parallel.enabled` | `'processes' => N` in flow options |
| `testsuites` | `flows` (named groups of jobs) |

## Example migration

**GrumPHP (`grumphp.yml`):**
```yaml
grumphp:
  tasks:
    phpstan:
      configuration: phpstan.neon
      level: 8
    phpcs:
      standard: PSR12
      ignore_patterns:
        - vendor/
    phpunit:
      config_file: phpunit.xml
```

**GitHooks (`githooks.php`):**
```php
<?php
return [
    'hooks' => [
        'pre-commit' => ['qa'],
    ],

    'flows' => [
        'options' => ['processes' => 2],
        'qa' => ['jobs' => ['phpstan_src', 'phpcs_src', 'phpunit_all']],
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
            'ignore'   => ['vendor'],
        ],
        'phpunit_all' => [
            'type'   => 'phpunit',
            'config' => 'phpunit.xml',
        ],
    ],
];
```

## Key differences

| Feature | GrumPHP | GitHooks |
|---|---|---|
| **Distribution** | Composer dependency (mixes with project deps) | `.phar` standalone (isolated dependencies) |
| **Config format** | YAML | PHP (YAML deprecated) |
| **Execution** | Sequential by default | Parallel with thread budget |
| **Fast mode** | Limited (file-based) | `--fast` (staged files) + `--fast-branch` (branch diff) |
| **Multiple hooks** | Pre-commit focused | All git events with conditions |
| **Output** | Text | Text, JSON, JUnit |
| **Job inheritance** | Not supported | `extends` |
| **Custom commands** | Via script task | `custom` type with simple and structured modes |

## Steps

1. Create `githooks.php` based on your `grumphp.yml` (see example above).
2. Install GitHooks: `composer require --dev wtyd/githooks`
3. Install hooks: `githooks hook`
4. Validate: `githooks conf:check`
5. Remove GrumPHP: `composer remove --dev phpro/grumphp`
6. Delete `grumphp.yml`.
