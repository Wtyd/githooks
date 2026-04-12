# Flows

A flow is a named group of jobs that run together with shared execution options.

## Basic syntax

```php
'flows' => [
    'qa' => [
        'jobs' => ['phpcs_src', 'phpmd_src', 'phpstan_src'],
    ],
],
```

## Flow options

Each flow can have its own `options` that control execution behavior:

```php
'flows' => [
    'myPrecommit' => [
        'options' => ['fail-fast' => true],
        'jobs'    => ['phpcbf_src', 'phpcs_src', 'phpmd_src'],
    ],
],
```

## Global vs local options

Options can be set globally (for all flows) or locally (per flow). Local options override global options:

```php
'flows' => [
    'options' => [
        'fail-fast' => false,
        'processes' => 2,
    ],

    'myPrecommit' => [
        'options' => ['fail-fast' => true],  // overrides global fail-fast
        'jobs'    => ['phpcbf_src', 'phpcs_src', 'phpmd_src'],
    ],

    'myPrepush' => [
        // inherits global options: fail-fast=false, processes=2
        'jobs' => ['phpstan_src', 'phpunit_all'],
    ],
],
```

In this example, `myPrecommit` overrides `fail-fast` but inherits `processes` from global options.

See [Options](options.md) for the full list of available options.

## Flow keywords

| Keyword | Description |
|---|---|
| `options` | Array. Execution options for this flow. Overrides global options. |
| `jobs` | Array. Names of jobs to execute, in order. |

## Reusability

A flow can be:

- Referenced from multiple hooks.
- Executed directly from the CLI with `githooks flow <name>`.
- Combined with other flows in the same hook event.

```php
'hooks' => [
    'pre-commit' => ['lint'],
    'pre-push'   => ['lint', 'full'],  // reuses 'lint' + adds 'full'
],

'flows' => [
    'lint' => ['jobs' => ['phpcs_src', 'phpmd_src']],
    'full' => ['jobs' => ['phpstan_src', 'phpunit_all']],
],
```

## See also

- [Options](options.md) — all execution options (processes, fail-fast, main-branch).
- [Jobs](jobs.md) — how to define the individual tasks that flows execute.
