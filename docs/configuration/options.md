# Options

Execution options control how flows run their jobs. They can be set globally (for all flows) or per flow.

## Available options

| Keyword | Type | Default | Description |
|---|---|---|---|
| `processes` | Integer | `1` | Total CPU cores budget for parallel execution. |
| `fail-fast` | Boolean | `false` | Stop the flow when a job fails. |
| `ignoreErrorsOnExit` | Boolean | `false` | Flow returns exit 0 even if any job has errors. Overrides job-level setting. |
| `main-branch` | String | Auto-detected | Main branch name for `fast-branch` diff computation. |
| `fast-branch-fallback` | String | `'full'` | Fallback when `fast-branch` cannot compute the diff (e.g. shallow clone). `'full'` runs with all paths; `'fast'` falls back to staged files only. |
| `executable-prefix` | String | `''` | Command prefix prepended to all job executables (e.g. `'docker exec -i app'`). |

## Priority

Options are resolved from lowest to highest priority:

1. **Defaults** — built-in values.
2. **Global options** — `flows.options`.
3. **Flow options** — per-flow `options`.
4. **CLI flags** — `--fail-fast`, `--processes=N` override everything.

```php
'flows' => [
    'options' => [
        'fail-fast' => false,      // global default
        'processes' => 2,
    ],

    'qa' => [
        'options' => ['fail-fast' => true],  // overrides for this flow
        'jobs' => ['phpcs_src', 'phpstan_src'],
    ],
],
```

Running `githooks flow qa --processes=4` would use `fail-fast=true` (from flow) and `processes=4` (from CLI).

## Thread budget

The `processes` option controls the **total CPU cores** available, not just the number of parallel jobs. When `processes > 1`, GitHooks distributes threads across jobs that support internal parallelism:

| Tool | Internal parallelism flag |
|---|---|
| phpcs / phpcbf | `--parallel` |
| parallel-lint | `-j` |
| psalm | `--threads` |
| phpstan | Worker count from `.neon` config (not adjustable at runtime) |

For example, with `processes: 4` and two threadable jobs, each gets approximately 2 threads. Use `githooks flow <name> --monitor` to see the actual thread usage.

See [How-To: Parallel Execution](../how-to/parallel-execution.md) for detailed examples.

## Fail-fast and ignoreErrorsOnExit

`fail-fast` and `ignoreErrorsOnExit` are **not compatible at flow level**. If both are `true`, `ignoreErrorsOnExit` is ignored.

However, `fail-fast` is compatible with `ignoreErrorsOnExit` at **job level**. A job with `ignoreErrorsOnExit: true` will not trigger the flow's fail-fast, even if it detects problems.

```php
'flows' => [
    'safe' => [
        'options' => ['ignoreErrorsOnExit' => true],
        'jobs'    => ['first_job', 'second_job'],  // flow always returns exit 0
    ],

    'strict' => [
        'options' => ['fail-fast' => true],
        'jobs'    => ['first_job', 'third_job'],   // stops at first failure
    ],
],
```

The `ignoreErrorsOnExit` at flow level overrides the same option for all jobs in that flow.

## Executable prefix

The `executable-prefix` option prepends a command to every job's executable. This is the key to running GitHooks inside Docker, Laravel Sail, or any remote environment.

```php
'flows' => [
    'options' => [
        'executable-prefix' => 'docker exec -i app',
    ],
],
```

With this, a job configured as `vendor/bin/phpstan analyse src` will run as `docker exec -i app vendor/bin/phpstan analyse src`.

### Per-job override

Individual jobs can override or opt out of the global prefix:

```php
'jobs' => [
    'phpstan_src' => [
        'type'  => 'phpstan',
        'paths' => ['src'],
        // Uses global prefix (docker exec -i app vendor/bin/phpstan ...)
    ],
    'eslint_src' => [
        'type'              => 'custom',
        'script'            => 'npx eslint src/',
        'executable-prefix' => '',     // Opt out: runs locally, not in Docker
    ],
    'phpcs_remote' => [
        'type'              => 'phpcs',
        'paths'             => ['src'],
        'executable-prefix' => 'ssh server',  // Different prefix for this job
    ],
],
```

### Priority

1. **Per-job `executable-prefix`** — highest priority. Set to `''` (empty string) or `null` to explicitly opt out.
2. **Flow-level `options.executable-prefix`** — applies to all jobs in that flow.
3. **Global `flows.options.executable-prefix`** — applies to all jobs.

### Local override

The most common pattern is to set `executable-prefix` in `githooks.local.php` so each developer configures their own environment:

```php
// githooks.local.php (not committed)
<?php
return [
    'flows' => [
        'options' => [
            'executable-prefix' => 'docker exec -i app',
        ],
    ],
];
```

See [Configuration File: Local Override](file.md#local-override-githookslocalphp) and [How-To: Docker & Local Override](../how-to/docker-local-override.md).
