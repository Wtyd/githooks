# Troubleshooting

Common error and warning messages emitted by [`conf:check`](cli/conf-check.md), by the runtime when executing a flow or job, and by the hook installer. Look up the literal text you got in the snippets below.

## `conf:check` errors

Run [`githooks conf:check`](cli/conf-check.md) to validate your configuration without executing any tool. The command exits 1 on the first error and lists warnings inline.

### Flows

| Message | Cause | Fix |
|---|---|---|
| `Flow 'X' declares both 'jobs' and 'flows'; pick one.` | A flow has both keys. A flow is either a regular flow (`jobs`) or a meta-flow (`flows`). | Remove the one you don't need. See [Meta-flows](configuration/flows.md#meta-flows). |
| `Flow 'X' has neither 'jobs' nor 'flows'.` | The flow entry is empty. | Declare `jobs: [...]` (regular) or `flows: [...]` (meta-flow). |
| `Flow 'X' must have a non-empty 'jobs' array.` | `jobs: []` or wrong type. | Add at least one job ref. |
| `Meta-flow 'X' must have a 'flows' array.` / `'flows' must be a list of non-empty strings.` | Wrong shape for a meta-flow. | Use `'flows' => ['qa', 'lint']` referencing existing regular flows. |
| `Flow 'X': 'execution' must be one of: full, fast, fast-branch, fast-dirty.` | Unknown value in `flows.<X>.execution`. | Pick a valid [execution mode](execution-modes.md). |

### Branch-driven execution mode (`on`)

| Message | Cause | Fix |
|---|---|---|
| `Flow 'X': 'on' must be an array of branch patterns.` | `on` is not a map. | Use `'on' => ['master' => ['execution' => 'full'], '*' => ['execution' => 'fast-branch']]`. |
| `Flow 'X' on rule: branch pattern must not be empty.` | Empty key in the `on` map. | Remove the empty entry. |
| `Flow 'X' on rule for 'pattern': attributes must be an object.` | The value next to a branch pattern is not an array. | Wrap attributes in an array: `'master' => ['execution' => 'full']`. |
| `Flow 'X' on rule for 'pattern': 'execution' must be one of: …. Did you mean '…'?` | Typo in the mode name. | Apply the suggestion. |
| `Flow 'X' on rule for 'pattern': unknown attribute 'foo'. Did you mean 'execution'?` | Unknown attribute under `on.<pattern>`. | Today only `execution` is supported. |

See [Branch-driven execution mode (`on`)](configuration/flows.md#branch-driven-execution-mode-on).

### Per-entry admission (`only-files` / `exclude-files`)

| Message | Cause | Fix |
|---|---|---|
| `Flow 'X' job ref 'Y': 'only-files' must be a string, array of strings, or null.` | Wrong type. | Use an array of glob strings, or `null` to cancel an inherited rule. |
| `Flow 'X' job ref 'Y': 'only-files' must not be empty. Use null to disable an inherited rule.` | `'only-files' => []`. | Use `null` to disable inheritance; an empty list is meaningless. |
| `Flow 'X' job ref 'Y': 'only-files' contains an empty pattern.` | One element is `''`. | Remove or replace with a real glob. |
| `Flow 'X' job ref 'Y': 'only-files' contains duplicate pattern 'P'.` | Same pattern listed twice. | Deduplicate. |
| `Flow 'X' job ref 'Y': unknown key 'K'. Did you mean '…'?` | A flow-entry object accepts only `job`, `needs`, `only-files`, `exclude-files`. | Fix the typo. |

See [Per-entry admission rules](configuration/flows.md#per-entry-admission-rules-only-files-exclude-files).

### Job dependencies (`needs`)

| Message | Cause | Fix |
|---|---|---|
| `Flow 'X': 'needs' has a cycle: A -> B -> A.` | The dependency graph contains a cycle. | Break the cycle. Self-loops (`A -> A`) and transitive cycles are both reported. |
| `Flow 'X': job 'Y' is declared more than once.` | The same job appears twice under `flows.<X>.jobs`. | Keep one entry; merge `needs` / admission rules into it. |
| `Flow 'X' job ref 'Y': 'needs' must be a string, array of strings, or null.` | Wrong type. | Use an array of job names, or `null` to cancel an inherited rule. |
| `Flow 'X' job ref 'Y': 'needs' must not be empty. Use null to disable an inherited rule.` | `'needs' => []`. | Use `null` instead. |
| `Flow 'X' job ref 'Y': 'needs' contains an empty job name.` / `duplicate job name 'Z'.` | Bad item in the list. | Fix or remove the offending entry. |

See [Job dependencies (`needs`)](configuration/flows.md#job-dependencies-needs).

### Options and budgets

| Message | Cause | Fix |
|---|---|---|
| `'warn-after' (N) must be less than 'fail-after' (M).` | Inverted thresholds. | `warn-after` must trigger before `fail-after`. |
| `'warn-above' (N) must be less than 'fail-above' (M).` | Inverted memory thresholds. | Same rule. |
| `'fail-after' must be a positive integer (seconds).` | Zero, negative or non-integer. | Use a positive integer. |
| `'warn-above' must be a positive integer (MB).` | Same family for memory budgets. | Use a positive integer in megabytes. |
| `'memory' must be either a positive integer (MB) or an object …` | Wrong shape on per-job `memory`. | Use `'memory' => 1500` (short form) or `'memory' => ['reserved' => N, 'warn-above' => …, 'fail-above' => …]`. |
| `'reports' must be a map of format => path.` | Wrong shape. | Use `'reports' => ['sarif' => 'reports/qa.sarif']`. |

See [Options reference](configuration/options.md).

### Unknown keys (warnings)

Unknown keys never block execution — they always surface as **warnings** with a *did-you-mean* suggestion when the typo is close to a known key. Example:

```
⚠ Flow 'qa' job ref 'phpstan_src': unknown key 'only_files'. Did you mean 'only-files'?
```

Apply the suggestion; the canonical keys use kebab-case.

## CLI conflicts

| Message | Cause | Fix |
|---|---|---|
| `--files and --files-from are mutually exclusive` | Both flags supplied. | Pick one — CSV list or manifest file. |
| `--fast-dirty and --fast are mutually exclusive` (or any other pair from `--fast` / `--fast-branch` / `--fast-dirty` / `--files` / `--files-from`) | More than one input-set flag. | Each flag defines a different "what to analyse" semantics; pick one. |
| `Options --exclude-jobs and --only-jobs cannot be used together.` | Both flags supplied. | Use one or the other. |

## Runtime — why a job was skipped (`skipReason`)

When a job has `skipped: true` in `--format=json` (or appears with the `⏭` annotation in text mode), the `skipReason` field tells you why. Reference of the values you can see:

| `skipReason` | Meaning |
|---|---|
| `no changes to validate` | `--fast` / `--fast-branch` / `--fast-dirty` produced an empty input set (clean working tree, branch matches base, no staged files). The mode intentionally does **not** fall back to `full`. |
| `no staged files match its paths` | `--fast`: the change set is non-empty but no staged file falls under the job's `paths`. |
| `no input files match its paths` | `--files` / `--files-from`: no file in the supplied list matches the job's `paths`. |
| `no input files provided` | `--files` empty after CSV split, or `--files-from` manifest empty after stripping blanks / comments. |
| `tool reported no input files after applying internal exclusions` | PHPStan (`No files found to analyse`) or PHPCS (`All specified files were excluded`) dropped 100 % of the input through its own ruleset. Tolerated since 3.3.3. |
| `<rule> filter excluded all files` | A per-entry `only-files` / `exclude-files` rule emptied the admission set. The rule name appears in the message. |
| `needs X failed` | Upstream dependency in `needs` failed. |
| `needs X was skipped` | Upstream dependency was skipped (e.g. by `only-files` or by an earlier `needs` propagation). |
| `needs A, B failed` | Multiple upstream failures. |
| `needs A failed, B was skipped` | Mixed causes. |
| `skipped by fail-fast` | A previous job in the flow failed while `fail-fast: true` was active and this job had not yet started. |
| `flow memory-budget exceeded` | The flow-level memory budget fired `fail-above`; the job was either killed or never admitted. |

## Hook installation

| Message | Cause | Fix |
|---|---|---|
| `No hooks defined in configuration. Nothing to install.` | The config file has no `hooks` block or it is empty. | Declare at least one hook event under `hooks`. See [Configuration: Hooks](configuration/hooks.md). |
| `Could not delete $hook hook` / `Error installing hook $hook` | Permission issue writing to `.githooks/`. | Check filesystem permissions; ensure `.githooks/` is writable. |
| Existing hooks from another tool | `githooks hook` backs up pre-existing hook scripts to `<hook>.bak` before overwriting. | Inspect the `.bak` file and decide whether to keep the GitHooks install or restore the original. |

## Configuration file loading

| Message | Cause | Fix |
|---|---|---|
| `Configuration file not found: $path` | `--config=PATH` points at a non-existent file. | Verify the path. |
| `PHP configuration file does not return an array.` | The `.php` config file is missing `return [ ... ];`. | Ensure the file ends with `return $config;` or similar. |
| `GitHooks only supports php 7.4 or greater.` | PHP version too old. | Upgrade PHP. |
| `There must be at least one tool configured.` | Empty `jobs` block. | Declare at least one job. |
| `Could not read legacy configuration.` | v2 YAML configuration is malformed. | Run [`conf:migrate`](cli/conf-migrate.md) to convert to v3 PHP format, or fix the YAML. |

## CI- and environment-specific

### `--fast-branch` with shallow clones

`actions/checkout@v4` defaults to `fetch-depth: 1`, which leaves no history to diff against the base branch. The job will fall back according to [`fast-branch-fallback`](configuration/options.md#available-options) (defaults to `full`). To use a real diff in CI:

```yaml
- uses: actions/checkout@v4
  with:
    fetch-depth: 0   # full history
```

### Detached HEAD with `flows.<X>.on`

If a flow declares an [`on`](configuration/flows.md#branch-driven-execution-mode-on) map and the run happens on a detached HEAD (`git rev-parse --abbrev-ref HEAD` returns `HEAD`), the resolver errors out and points you at:

- `--branch=X` (CLI flag on `githooks flow`)
- `$GITHOOKS_BRANCH` (environment variable)

Set one of those when running on detached HEAD. Flows without `on` continue to work on detached HEAD unchanged.

### Memory budget on Windows

RSS sampling is not available on Windows. The runtime emits a one-time warning:

```
⚠ Memory budget disabled: RSS sampling not available on Windows
```

…and disables `memory-budget` and per-job `memory:` thresholds. The 2D allocator still schedules using reservations; `--stats` still reports cores info.

## Surprising behaviours and how to avoid them

### Skip cascades from `needs`

Declaring `only-files` on an upstream job but not on its dependents propagates as `skipReason: 'needs X was skipped'`. This is correct but can surprise. To make both skip in lockstep (the intended pattern in monorepos), declare the **same** `only-files` on the dependent:

```php
'jobs' => [
    ['job' => 'yarn_install', 'only-files' => ['**/*.{js,ts,vue,json}']],
    ['job' => 'eslint',
     'needs'      => ['yarn_install'],
     'only-files' => ['**/*.{js,ts,vue,json}'],   // mirror the upstream
    ],
],
```

See [Composition with `only-files` / `exclude-files`](configuration/flows.md#composition-with-only-files-exclude-files).

### `fail-fast` in 3.4 no longer terminates running jobs

Jobs already running when another fails are allowed to finish naturally. `fail-fast` cancels **pending** work, not in-flight work. Direct or transitive descendants in the `needs` DAG receive `skipReason: 'needs X failed'`; siblings receive `skipped by fail-fast`. Previous releases sent SIGTERM to running jobs — that behaviour is gone.

### Per-flow options ignored in multi-flow runs

When you invoke `githooks flows X Y` (two normal flows) or a mixed meta-flow + extra flows run, per-flow `options:` blocks are intentionally ignored. The cascade collapses to `cli > flows.options > default`. A one-line stderr notice names the ignored sources so you know what is not being applied.

See [`githooks flows` invocation modes](cli/flows.md#invocation-modes).
