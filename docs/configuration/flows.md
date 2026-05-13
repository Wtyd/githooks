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

## Per-entry admission rules (`only-files` / `exclude-files`)

> Since **v3.4** (FEAT-1).

Each entry in `flows.<X>.jobs` can be either a plain string (job name) **or** an object that declares glob-based admission rules:

```php
'flows' => [
    'tests' => [
        'jobs' => [
            ['job' => 'tests_a', 'only-files' => ['src/A/**', 'composer.json', 'composer.lock']],
            ['job' => 'tests_b', 'only-files' => ['src/B/**', 'composer.json', 'composer.lock']],
            'lint_full',  // string entry: no admission rule, runs always
        ],
    ],
],
```

When the flow runs in `--fast` / `--fast-branch`, each entry's rules are evaluated against the change set:

- A file `F` **admits** the job when `match(only-files, F) AND NOT match(exclude-files, F)`.
- The job **runs** if at least one file admits it; otherwise it appears in the output as `skipped: true` with a clear `skipReason` (consistent with JSON / SARIF / JUnit reporting).
- In `full` mode the rules are **no-op** so `flow qa` ran manually keeps validating the whole project.

The rules are a **binary admission gate**, decoupled from the job's input filtering: `phpunit` admitted by a rule still runs its full suite (it is non-accelerable); `phpcs` admitted by a rule still filters its `paths` as before.

### Glob syntax

Same as `HookRef` and `--exclude-pattern`:

| Pattern | Matches |
|---|---|
| `*` | Anything except `/` |
| `**` | Zero or more directories |
| `?` | Exactly one character except `/` |

### Composition with hook-level rules

Hook-level conditions (`hooks.<event>.<ref>.only-files`) and flow-entry rules **compose by AND** across levels: the hook ref decides whether the flow runs at all; if it runs, each flow entry then decides per job. Both levels operate on the same change set.

### Overriding in `githooks.local.php`

The override semantics mirror `time-budget` / `memory-budget`:

| Local declaration | Effect on the inherited rule |
|---|---|
| `'only-files' => null` | Cancels the inherited rule. Job runs unfiltered by `only-files`. |
| key absent | Inherits the rule from the shared config unchanged. |
| `'only-files' => ['lib/**']` | Replaces the rule. **Caveat below.** |
| `'only-files' => []` | `conf:check` error: empty list is meaningless. Use `null` to disable. |

The same rules apply to `exclude-files`.

#### Merge caveat (inherited from `array_replace_recursive`)

When both the shared config and `.local.php` declare lists of **different length**, PHP merges them **per index** instead of replacing the whole list:

```php
// shared
'only-files' => ['src/A/**', 'composer.json']
// local
'only-files' => ['src/X/**']

// effective (per-index merge — composer.json from shared survives)
'only-files' => ['src/X/**', 'composer.json']
```

This is a generic limitation of the project's merge strategy (it affects every list: `Tools`, `paths`, `jobs`, …), not specific to FEAT-1. Recommended pattern for a clean replacement: declare `null` in the shared config and move the actual list to `.local.php`, or rewrite the local list with the same length as the shared one.

## Meta-flows

A **meta-flow** is a flow that, instead of declaring `jobs`, declares `flows` — a list of normal flow names to combine into a single executable plan. It is the declarative companion to [`githooks flows ci-pack`](../cli/flows.md): the combo lives with the project, runs the same locally and in CI, and exposes its own options.

```php
'flows' => [
    'options' => ['processes' => 1],

    'qa'   => ['jobs' => ['phpcs_src', 'phpstan_src']],
    'lint' => ['jobs' => ['phpcs_src', 'phpmd_src']],

    'ci-pack' => [
        'flows'   => ['qa', 'lint'],
        'options' => [
            'processes' => 4,
            'fail-fast' => true,
            'reports'   => ['sarif' => 'qa.sarif', 'junit' => 'qa.xml'],
        ],
    ],
],
```

### Rules

- A `flows.<X>` entry declares **exactly one** of `jobs` (normal flow) or `flows` (meta-flow). Both or neither is a `conf:check` error.
- `flows.<alias>.flows` may only reference **normal flows** that exist in config. Nesting one meta-flow inside another is rejected (`conf:check` error). The pattern instead is to declare a meta-flow that enumerates the desired normal flows directly.
- The names of jobs, normal flows, and meta-flows share a **single flat namespace**. Two of them cannot share a name; `conf:check` reports the collision.
- A meta-flow can declare `options` of its own (same shape as `flows.<X>.options` for normal flows). They apply **only** when the meta-flow is invoked alone (`githooks flows ci-pack`); they are ignored in mixed runs (`githooks flows ci-pack other`) and in ad-hoc combinations.
- Empty (`flows: []`) or single-element (`flows: ['qa']`) meta-flows are accepted but produce a `conf:check` warning suggesting a redesign.

### Expansion mechanics

When `githooks flows ci-pack other` runs, the resolver follows the spec:

1. Each argument is replaced by either itself (normal flow) or its `flows: [...]` references (meta-flow).
2. The expanded list is deduplicated by first occurrence.
3. The jobs of the resulting flows are concatenated and deduplicated by name.
4. CLI flags (`--exclude-jobs`, `--only-jobs`, `--fast`, `--files`, …) apply to the merged union.

`conf:check` shows the distinction in the flows table: meta-flows appear as `name (meta) → qa, lint`.

## See also

- [Options](options.md) — all execution options (processes, fail-fast, main-branch).
- [Jobs](jobs.md) — how to define the individual tasks that flows execute.
- [`githooks flows`](../cli/flows.md) — running combined flows from the CLI.
