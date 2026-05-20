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

## Branch-driven execution mode (`on`)

> Since **v3.4** (FEAT-2).

A flow can declare an `on` map that picks its execution mode based on the current branch:

```php
'flows' => [
    'ci-validation' => [
        'on' => [
            'master' => ['execution' => 'full'],
            'beta'   => ['execution' => 'full'],
            'main'   => ['execution' => 'full'],
            '*'      => ['execution' => 'fast-branch'],
        ],
        'jobs' => [/* ... */],
    ],
],
```

In CI this collapses the per-rules-template `GITHOOKS_FLAGS=--fast-branch` boilerplate to a single line:

```yaml
script: vendor/bin/githooks flows ci-validation
```

In `master` the mode is `full` (FEAT-1's `only-files` rules become no-op). In a task branch the mode is `fast-branch` and admission filters apply.

### Resolution cascade

The effective mode is decided in this order (first that produces a value wins):

1. `--fast` / `--fast-branch` CLI flags.
2. `flows.<X>.on` matched against the current branch.
3. `flows.<X>.execution`.
4. `flows.options.execution` (global default).
5. Fallback: `full`.

The `Settings:` header at the top of each run shows the source — `mode = full (flows.<X>.on)` makes the decision trail explicit.

### Branch detection cascade

The current branch is read with the following priority (first non-empty wins):

1. `--branch=<name>` CLI flag (FEAT-2 — useful for testing or local override).
2. `$GITHOOKS_BRANCH` env var — explicit user override.
3. CI variables in this order: `CI_COMMIT_REF_NAME` (GitLab), `GITHUB_REF_NAME` (GitHub Actions), `BUILDKITE_BRANCH` (Buildkite), `BITBUCKET_BRANCH` (Bitbucket Pipelines), `CIRCLE_BRANCH` (CircleCI), `DRONE_COMMIT_BRANCH` (Drone), `TRAVIS_PULL_REQUEST_BRANCH` (PR build) / `TRAVIS_BRANCH` (push build).
4. `git rev-parse --abbrev-ref HEAD`.
5. Otherwise (detached HEAD): the run aborts with an error pointing the user at `--branch` or `$GITHOOKS_BRANCH`.

The resolver is only invoked when the flow declares `on`. Flows without `on` keep running unchanged on a detached HEAD.

### Pattern matching

The order of patterns in the map is the priority order: **the first pattern that matches wins** — literal or glob. Same glob syntax as elsewhere in the project: `*` matches anything except `/`, `**` matches zero or more directories, `?` matches one character except `/`. Catch-all is `'*'`.

```php
'on' => [
    'release/v*' => ['execution' => 'full'],          // matches release/v1, release/v2
    'release/*'  => ['execution' => 'fast-branch'],   // catches the rest of release/*
    '*'          => ['execution' => 'fast'],          // everything else
],
```

`conf:check` emits a warning when no catch-all `'*'` is declared so the user knows non-matching branches will fall back to `flow.execution` or `flows.options.execution`.

### Overriding in `githooks.local.php`

Same semantics as FEAT-1's `only-files` / `exclude-files`:

| Local declaration | Effect on the inherited rule |
|---|---|
| `'on' => null` | Cancels the inherited `on` map. Mode falls through to lower cascade levels. |
| key absent | Inherits the shared map unchanged. |
| `'on' => [...]` | Per-pattern deep merge (associative keys). Patterns present in local override the shared definition for that pattern; patterns only in shared survive. |

### Composition with hook-level conditions

`hooks.<event>.<ref>.only-on` admits the flow to the hook event by branch; `flows.<X>.on` selects the mode of the admitted flow. Levels are orthogonal — a hook with `only-on: master` plus a flow with `on: '*' => fast-branch` would never run because the hook admission fires only on `master`, where the flow would pick `full` if so configured.

### Scope and caveats

- **Per-flow only.** Multi-flow runs (`githooks flows X Y`) ignore per-flow `on` (matches the existing CON-001/002 for flow-level options). The mode comes from `--fast/--fast-branch` or `flows.options.execution`.
- **`execution` is the only supported attribute today.** The object shape leaves room for `time-budget` / `fail-fast` to be added later without breaking the surface.
- **`PHP collapses duplicate map keys.** `'master' => …, 'master' => …` cannot be detected — PHP keeps only the last entry.

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
