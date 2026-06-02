# Jobs

A job describes a single QA task. Every job must have a `type` that determines which tool it runs and which keywords it accepts.

## Basic syntax

```php
'jobs' => [
    'phpcs_src' => [
        'type'     => 'phpcs',
        'paths'    => ['src'],
        'standard' => 'PSR12',
        'ignore'   => ['vendor'],
    ],
],
```

## Supported types

| Type | Tool | Accelerable |
|---|---|---|
| `phpstan` | [PHPStan](../tools/phpstan.md) | Yes |
| `phpcs` | [PHP CodeSniffer](../tools/phpcs.md) | Yes |
| `phpcbf` | [PHP Code Beautifier](../tools/phpcs.md) | Yes |
| `phpmd` | [PHP Mess Detector](../tools/phpmd.md) | Yes |
| `parallel-lint` | [Parallel Lint](../tools/parallel-lint.md) | Yes |
| `psalm` | [Psalm](../tools/psalm.md) | Yes |
| `phpunit` | [PHPUnit](../tools/phpunit.md) | No |
| `paratest` | [Paratest](../tools/paratest.md) | No |
| `phpcpd` | [PHP Copy Paste Detector](../tools/phpcpd.md) | No |
| `custom` | [Any command](../tools/custom.md) | Opt-in |
| `commit-msg` | [Commit message validation](../tools/commit-msg.md) | No |

See the [Tools Reference](../tools/index.md) for the full keyword documentation of each type.

## Common keywords

The following keywords are available for all job types (except `custom` and `commit-msg`, which have their own sets):

| Keyword | Type | Description |
|---|---|---|
| `type` | String | Determines the tool and accepted keywords. **Mandatory.** |
| `executable-path` | String | Path to the tool binary. If omitted, auto-detects `vendor/bin/{tool}`, then falls back to system PATH. |
| `paths` | Array | Directories or files to analyze. |
| `other-arguments` | String | Extra CLI flags not natively supported by GitHooks. |
| `ignore-errors-on-exit` | Boolean | Job returns exit 0 even with problems. Default `false`. |
| `fail-fast` | Boolean | Stop remaining jobs in the flow if this one fails. Default `false`. |
| `accelerable` | Boolean | Override `--fast` behavior. Default depends on type. |
| `execution` | String | Per-job execution mode override: `full`, `fast`, `fast-branch`, or `fast-dirty`. |
| `executable-prefix` | String | Per-job prefix override. Set to `null` or `''` to opt out of the global prefix. |
| `cores` | Integer | Reserve N cores in the [thread budget](options.md#thread-budget). See [Reserving cores explicitly](#reserving-cores-cores-or-the-tools-native-flag) below. |
| `memory` | Integer or Object | Per-job memory threshold (MB) — and 2D allocator reservation when given as a short integer. See [Per-job memory threshold](#per-job-memory-threshold-memory) below. |
| `warn-after` / `fail-after` | Integer | Per-job time thresholds (seconds). See [Per-job time threshold](#per-job-time-threshold-warn-after-fail-after) below. |

!!! tip
    Missing keys can cause the job to fail at runtime. For example, a `phpcs` job without `standard` may fail if no standard is configured in the tool's own config file.

!!! warning "Deprecated camelCase keys"
    The keys `executablePath`, `otherArguments`, `ignoreErrorsOnExit` and `failFast` (camelCase, inherited from v2) are still accepted in v3.3 with a deprecation warning, but they will be removed in **v4.0**. Use the kebab-case form shown in the table above. See [Migration: v3.3 deprecations](../migration/v33-deprecations.md).

## Reserving cores (`cores` or the tool's native flag)

Every job accepts a `cores: N` keyword (integer ≥ 1) that reserves N cores
in the [thread budget](options.md#thread-budget). On tools with their own
threading flag, **`cores: N` and the native flag are interchangeable** —
declare whichever feels natural and GitHooks does the right thing in both
cases:

- Reserves N cores in the allocator so the parallel pool admits jobs
  honouring the actual cost.
- Passes the tool the right flag (`--parallel`, `--threads`, `-j`,
  `--processes`) without you having to remember which one is which.

```php
'jobs' => [
    // Form 1: explicit `cores`. Tool-agnostic, easiest to read.
    'phpcs_src' => [
        'type'  => 'phpcs',
        'paths' => ['src'],
        'cores' => 2,        // → reserves 2 + emits --parallel=2
    ],

    // Form 2: native flag. Equivalent — the allocator promotes it.
    'paratest_all' => [
        'type'      => 'paratest',
        'processes' => 4,    // → reserves 4 + emits --processes=4
    ],
],
```

### Tool categories

| Category | Tools | `cores` / native flag |
|---|---|---|
| **Controllable** | `phpcs`, `phpcbf`, `psalm`, `parallel-lint`, `paratest` | Interchangeable. `cores: N` ↔ native flag. Allocator + flag both honoured. The flow's `processes` is the absolute ceiling — see [The flow rules](#the-flow-rules) below. |
| **Uncontrollable with internal threading** | `phpstan` | `cores: N` reserves N slots in the budget so `--monitor`/2D-allocator account accurately. The worker count itself is read from `.neon`'s `maximumNumberOfProcesses`; no CLI flag exists to force it. When the `.neon` value exceeds a flow's `processes`, [`conf:check`](../cli/conf-check.md) emits a cross-flow warning. |
| **Single-threaded** | `phpmd`, `phpunit`, `phpcpd` | The tool only uses 1 core. Declaring `cores > 1` reserves slots without benefit and slows admission of other jobs — `conf:check` emits a warning. Omit the key (or set `cores: 1`) on these jobs. |
| **`custom`** | any user script | No automatic detection. Declare `cores: N` if your script has its own concurrency (`npx eslint --concurrency=N`, parallel make, etc.); the single-threaded warning is suppressed because the system can't inspect external scripts. When `cores > flow.processes`, the same cross-flow warning as phpstan applies. |

### When both forms are declared

If a job declares both `cores` and the native flag at the same time
(e.g. `cores: 2` and `parallel: 4` on a phpcs job), `cores` wins at
runtime and [`conf:check`](../cli/conf-check.md) emits a warning naming
both values. Pick one and remove the other.

### The flow rules

A job can be referenced from multiple flows with different `processes`
budgets — typically `local` on a developer's machine and `ci` on a beefier
runner. The recommended pattern is to declare the **maximum** capacity in
the job; each flow caps it according to its own `processes`:

```php
'flows' => [
    'local' => ['options' => ['processes' => 4],  'jobs' => ['phpcs_src']],
    'ci'    => ['options' => ['processes' => 16], 'jobs' => ['phpcs_src']],
],
'jobs' => [
    'phpcs_src' => [
        'type'  => 'phpcs',
        'paths' => ['src'],
        'cores' => 8,   // declared maximum
    ],
],
```

- In `local` (`processes: 4`), the runtime emits `--parallel=4` and reserves 4 cores.
- In `ci` (`processes: 16`), the runtime emits `--parallel=8` and reserves 8 cores.

The same clamp applies to the native flag when declared without `cores`
(e.g. `parallel: 8`, `threads: 8`), to the default capability of
controllable tools (parallel-lint's default `jobs: 10`, paratest's
default `processes: 4`), and to `cores`/`neon-workers` for uncontrollable
jobs — except that for phpstan and `type: custom` GitHooks cannot
**force** the limit (phpstan reads `.neon` directly, custom scripts are
opaque). For these two, [`conf:check`](../cli/conf-check.md) emits a
cross-flow warning naming the affected flow so you can adjust the
declaration or accept that other jobs in that flow will wait in serial.

## Per-job memory threshold (`memory`)

Each job can declare a memory threshold that the runtime watches via RSS
sampling (Linux and macOS in v3.3; Windows degrades gracefully with
a stderr warning). The key has two equivalent forms:

```php
'jobs' => [
    // Short form: integer (MB). Acts as both warn-above threshold AND
    // scheduler reservation when a flow `memory-budget` is declared.
    'phpstan-src' => [
        'type'   => 'phpstan',
        'cores'  => 2,
        'memory' => 2000,
    ],

    // Extended form: object. Threshold-only — does NOT reserve at the
    // scheduler. Use this when you want a hard `fail-above` cap or to
    // declare warn-above only, without participating in 2D bin-packing.
    'phpunit' => [
        'type'   => 'phpunit',
        'cores'  => 4,
        'memory' => [
            'warn-above' => 1500,
            'fail-above' => 2000,
        ],
    ],

    // Extended form with only warn-above (no fail).
    'phpcs' => [
        'type'   => 'phpcs',
        'memory' => ['warn-above' => 256],
    ],
],
```

**Behaviour:**

- Crossing `warn-above` adds a `⚠` annotation to the job; the flow exits
  `0` (the job still passes).
- Crossing `fail-above` flips the job to KO with exit `1`, even when the
  tool itself returned `0`. A KO-real job (tool exit ≠ 0) that also
  crossed memory still reports KO-real as the primary cause; the
  threshold is annotated as a secondary line.
- Skipped jobs (no staged files, fast-mode filtering) do not contribute
  to the simultaneous flow peak nor evaluate thresholds.

**Calibration tip:** run with `--stats` once **without** thresholds to
discover real peaks, then declare conservative `warn-above`/`fail-above`
based on the table.

## Per-job time threshold (`warn-after` / `fail-after`)

Each job can declare flat `warn-after` / `fail-after` keys (seconds) to
catch local regressions in its own duration. They are independent of the
flow-level [`time-budget`](options.md#time-budget-time-budget) — the two
layers answer different questions ("is this *job* regressing?" vs. "is
the pipeline as a whole regressing?") and remain decoupled.

```php
'jobs' => [
    'phpunit' => [
        'type'       => 'phpunit',
        'config'     => 'phpunit.xml',
        'warn-after' => 120,   // ⚠ when this single job takes longer
        'fail-after' => 180,   // KO when this single job takes longer
    ],
],
```

**Behaviour:**

- Crossing `warn-after` adds a `⚠` annotation; the job still passes.
- Crossing `fail-after` flips the job to KO with exit `1`, even when the
  tool itself returned `0`.

`conf:check` rejects `warn-after >= fail-after`, non-positive integers,
and a `time-budget` key placed inside a job (the canonical job-level
keys are flat `warn-after` / `fail-after`; `time-budget` is reserved for
flow-level `flows.options`).

CLI override on a single-job run: `githooks job <name> --warn-after=N
--fail-after=N` (or `--no-time-budget` to disable both layers for that
run). See [Time budget](options.md#time-budget-time-budget) for the
flow-level counterpart and the cross-layer rules.

## Job inheritance (`extends`)

A job can inherit configuration from another job using the `extends` key. The child inherits all keys from the parent and can override any of them:

```php
'jobs' => [
    'phpmd_base' => [
        'type'    => 'phpmd',
        'rules'   => 'cleancode,codesize,naming,unusedcode',
        'exclude' => ['vendor'],
    ],
    'phpmd_src' => [
        'extends' => 'phpmd_base',
        'paths'   => ['src'],
    ],
    'phpmd_app' => [
        'extends' => 'phpmd_base',
        'paths'   => ['app'],
    ],
],
```

Both `phpmd_src` and `phpmd_app` inherit `type`, `rules`, and `exclude` from `phpmd_base`, each adding its own `paths`. The child can override any inherited key:

```php
'phpmd_light' => [
    'extends' => 'phpmd_base',
    'rules'   => 'unusedcode',   // overrides parent's rules
    'paths'   => ['tests'],
],
```

**Rules:**

- Chained inheritance works: A extends B extends C.
- Circular references are detected and reported as errors.
- The `extends` key is removed from the resolved job.
- The parent job can also be used directly in flows — it doesn't need to be abstract.

## A common pattern: phpcs + phpcbf

```php
'jobs' => [
    'phpcs_src' => [
        'type'     => 'phpcs',
        'paths'    => ['src', 'tests'],
        'standard' => 'PSR12',
        'ignore'   => ['vendor'],
    ],
    'phpcbf_src' => [
        'extends' => 'phpcs_src',  // inherits paths, standard, ignore
        'type'    => 'phpcbf',     // overrides type
    ],
],
```

## See also

- [Tools Reference](../tools/index.md) — type-specific keywords for each tool.
- [Execution Modes](../execution-modes.md) — how `accelerable` and `execution` affect which files are analyzed.
- [How-To: Job Inheritance](../how-to/job-inheritance.md) — more examples and patterns.
