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

> Since **v3.4**.

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

When the flow runs in `--fast`, `--fast-branch` or `--fast-dirty`, each entry's rules are evaluated against the change set:

- A file `F` **admits** the job when `match(only-files, F) AND NOT match(exclude-files, F)`.
- The job **runs** if at least one file admits it; otherwise it appears in the output as `skipped: true` with a clear `skipReason` (consistent with JSON / SARIF / JUnit reporting).
- In `full` mode the rules are **no-op** so `flow qa` ran manually keeps validating the whole project.

The rules are a **binary admission gate**, decoupled from the job's input filtering: `phpunit` admitted by a rule still runs its full suite (it is non-accelerable); `phpcs` admitted by a rule still filters its `paths` as before.

### Glob syntax

Same operators (`*`, `**`, `?`, `[abc]`, `{a,b,c}`) as hook-level conditions and `--exclude-pattern`. See the [Glob syntax reference](../glob-syntax.md) for the full table and common patterns.

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

This is a generic limitation of the project's merge strategy (it affects every list: `Tools`, `paths`, `jobs`, …), not specific to per-entry admission rules. Recommended pattern for a clean replacement: declare `null` in the shared config and move the actual list to `.local.php`, or rewrite the local list with the same length as the shared one.

## Branch-driven execution mode (`on`)

> Since **v3.4**.

A flow can declare an `on` map that picks its execution mode based on the current branch:

```php
'flows' => [
    'ci' => [
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

The execution mode now lives **inside the flow declaration**, so a single CI step covers both protected and feature branches:

```yaml
script: vendor/bin/githooks flows ci
```

No branch-aware `if:` conditional in the CI definition, no duplicated job (e.g. one for `master` running `full`, another for the rest running `--fast-branch`), no environment variable injected per pipeline template. On `master` the mode resolves to `full` (per-entry `only-files` rules become no-op); on a task branch it resolves to `fast-branch` and admission filters apply — all driven by config.

### Resolution cascade

The effective mode is decided in this order (first that produces a value wins):

1. `--fast` / `--fast-branch` / `--fast-dirty` CLI flags.
2. `flows.<X>.on` matched against the current branch.
3. `flows.<X>.execution`.
4. `flows.options.execution` (global default).
5. Fallback: `full`.

The `Settings:` header at the top of each run shows the source — `mode = full (flows.<X>.on)` makes the decision trail explicit.

### Branch detection cascade

The current branch is read with the following priority (first non-empty wins):

1. `--branch=<name>` CLI flag — useful for testing or local override.
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

Same semantics as per-entry `only-files` / `exclude-files`:

| Local declaration | Effect on the inherited rule |
|---|---|
| `'on' => null` | Cancels the inherited `on` map. Mode falls through to lower cascade levels. |
| key absent | Inherits the shared map unchanged. |
| `'on' => [...]` | Per-pattern deep merge (associative keys). Patterns present in local override the shared definition for that pattern; patterns only in shared survive. |

### Composition with hook-level conditions

`hooks.<event>.<ref>.only-on` admits the flow to the hook event by branch; `flows.<X>.on` selects the mode of the admitted flow. Levels are orthogonal — a hook with `only-on: master` plus a flow with `on: '*' => fast-branch` would never run because the hook admission fires only on `master`, where the flow would pick `full` if so configured.

### Composition with per-entry admission rules

`on` decides **which mode** the flow runs in per branch; [per-entry `only-files` / `exclude-files`](#per-entry-admission-rules-only-files-exclude-files) decides **which jobs** are admitted per change set. Together they let the flow declaration carry the full execution policy:

```php
'ci' => [
    'on' => [
        'master' => ['execution' => 'full'],
        '*'      => ['execution' => 'fast-branch'],
    ],
    'jobs' => [
        ['job' => 'phpstan_src',     'only-files' => ['src/**']],
        ['job' => 'phpcs_src',       'only-files' => ['src/**']],
        ['job' => 'phpunit_backend', 'only-files' => ['src/**', 'tests/**']],
        ['job' => 'eslint_frontend', 'only-files' => ['resources/js/**']],
    ],
],
```

A single `vendor/bin/githooks flows ci` CI step covers every branch, picks the mode internally, and admits each job based on the actual change set. The CI definition no longer needs branch-aware conditionals, per-job duplication or `rules:` / `changes:` filters (GitLab CI) and `paths:` filters (GitHub Actions) that depend on pipeline source and can be coarse on merge / scheduled pipelines. The admission decision lives next to the job, so the same logic is exercised locally (`flow ci --fast-branch`) and in CI.

### Scope and caveats

- **Per-flow only.** Multi-flow runs (`githooks flows X Y`) ignore per-flow `on` — same convention as every other flow-level option in multi-flow mode. The mode comes from `--fast`/`--fast-branch`/`--fast-dirty` or `flows.options.execution`.
- **`execution` is the only supported attribute today.** The object shape leaves room for `time-budget` / `fail-fast` to be added later without breaking the surface.
- **`PHP collapses duplicate map keys.** `'master' => …, 'master' => …` cannot be detected — PHP keeps only the last entry.

## Job dependencies (`needs`)

> Since **v3.4**.

A flow entry can declare which other jobs in the same flow it depends on:

```php
'flows' => [
    'qa' => [
        'options' => ['processes' => 4],
        'jobs' => [
            'yarn-install',                                       // string entry: no dependencies
            ['job' => 'eslint',   'needs' => ['yarn-install']],   // waits for yarn-install
            ['job' => 'prettier', 'needs' => ['yarn-install']],   // waits for yarn-install
            'phpstan',                                            // independent: starts in parallel
            'phpcs',                                              // independent
        ],
    ],
],
```

The admission gate holds `eslint` and `prettier` until `yarn-install` completes successfully — but `phpstan` and `phpcs` start immediately because they declare no dependency on `yarn-install`. With `processes: 4`, the wall time approaches the longest dependency chain rather than the sum of jobs.

### Semantics

| When | The dependent... |
|---|---|
| All `needs` finish successfully | runs normally. |
| At least one `need` failed | is skipped with `skipReason: 'needs X failed'` (lists every failed dep when multiple). |
| At least one `need` was skipped (and none failed) | is skipped with `skipReason: 'needs X was skipped'`. |
| Mixed (one failed, another skipped) | `skipReason: 'needs A failed, B was skipped'`. |

The skip propagates down the chain: if `eslint` is skipped because `yarn-install` failed, then `lint-fix` (`needs: ['eslint']`) is skipped with `'needs eslint was skipped'`. No invisible skips — every consequence carries its specific cause.

### Static validation (`conf:check`)

The dependency graph is validated at parse time, not at runtime. Errors:

- **Cycle of any length**: `A → A` (self-loop), `A → B → A`, `A → B → C → A`, etc. Reported with the offending chain — e.g. `Flow 'qa': 'needs' has a cycle: A -> B -> A.`
- **`needs` references a job not declared in the same flow** — cross-flow dependencies must be modelled with meta-flows.
- **Same job name declared twice in `jobs`** — needs would be ambiguous.
- **Empty list (`'needs' => []`)** — meaningless; use `null` to disable an inherited rule (see override below).

### Execution order (`processes: 1`)

In sequential mode the runtime processes jobs **already topologically sorted** at admission. The declaration order is preserved between nodes that are not related by `needs`, so the only visible effect is "things move earlier if other things needed them first" — never later.

### fail-fast behaviour

When `fail-fast: true` and a job fails:

- Jobs **already running** complete normally (no SIGTERM cascade).
- The remaining **queue** is skipped. Direct or transitive descendants of the failing job in the DAG receive `skipReason: 'needs X failed'`; siblings independent of the failure receive `skipReason: 'skipped by fail-fast'`.

Without `needs` declared, `fail-fast` falls back to the same semantics as previous versions (running terminates naturally; queue is skipped uniformly).

### Composition with `only-files` / `exclude-files`

Evaluation order: **`only-files`/`exclude-files` first, `needs` second**. If a job skips by `only-files`, its dependents propagate with `'needs X was skipped'`. To avoid surprising skip cascades, declare the **same `only-files`** on the dependent so both skip together via the admission rule instead of via propagation:

```php
'jobs' => [
    ['job' => 'yarn-install', 'only-files' => ['**/*.{js,ts,vue,json}']],
    ['job' => 'eslint',
     'needs'      => ['yarn-install'],
     'only-files' => ['**/*.{js,ts,vue,json}'],   // ← match the upstream
    ],
],
```

When there is no JS in the change set, both skip by `only-files` — coherent with the dev's intent.

### Composition with `on`

The execution mode chosen by `on` does not affect `needs`. Dependencies are structural — they hold in `full`, `fast`, `fast-branch`, and `fast-dirty` alike.

### Overriding in `githooks.local.php`

Same semantics as the other per-entry attributes (`only-files` / `exclude-files`) and as the per-flow `on` map:

| Local declaration | Effect on the inherited `needs` |
|---|---|
| key absent | Inherits the shared list. |
| `'needs' => null` | Cancels the inherited list. |
| `'needs' => ['lint']` | Replaces (with the documented `array_replace_recursive` index-merge caveat). |

### JSON v2

Each job entry surfaces its declared dependencies under `needs`:

```json
{
  "name": "eslint",
  "type": "parallel-lint",
  "needs": ["yarn-install"],
  "skipped": true,
  "skipReason": "needs yarn-install failed"
}
```

`needs` is omitted when empty (no dependencies declared) so the schema stays compact for the common case.

### TTY parallel dashboard

Jobs waiting on dependencies show a fourth state next to `running` / `queued` / `done`:

```
  ⏳ yarn-install [3.2s]
  ⏸ eslint (waiting yarn-install)
  ⏸ prettier (waiting yarn-install)
  ⏺ phpcs
```

In non-TTY output (CI logs) the waiting lane is silent — only the final results (run / skip with reason) appear.

### Out of scope for v3.4

- **Cross-flow `needs`** — use meta-flows for that.
- **GitLab-style conditions** (`when: 'on_failure'` / `when: 'always'`).
- **Matrix-style fan-out** — declare each variant as its own job.
- **`optional: true` per dependency** to opt out of propagation — waiting on a confirmed use case.

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
- A meta-flow can also declare [`on`](../execution-modes.md) (per-branch execution). Like `options`, it applies **only** when the meta-flow is invoked alone: the branch is resolved and the matching rule's execution mode propagates to the expanded flows. It is ignored in mixed/ad-hoc runs.
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
