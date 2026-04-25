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
| `reports` | Map<string,string> | `[]` | Map of `format => path` to write extra report files alongside `--format`/`--output`. See [Multi-report](#multi-report). |

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

### Per-job reservation (`cores`)

A job can opt out of the automatic split by declaring [`cores: N`](jobs.md#reserving-cores-explicitly-cores). The allocator reserves exactly N cores for that job and, when the tool is controllable (phpcs, psalm, parallel-lint, paratest), passes the corresponding flag automatically. Useful to guarantee a specific budget for paratest workers or to pin a phpstan configuration.

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

## Multi-report

The `reports` option emits one or more report files in a single flow run, in the style of PHPUnit's `--log-junit`/`--coverage-html` flags or Psalm's `--report=`. Useful when a CI pipeline needs **SARIF** for GitHub Code Scanning, **JUnit** for the test dashboard and **Code Climate** for GitLab MR widgets — without re-running the analysis three times.

### Declarative configuration

```php
'flows' => [
    'qa' => [
        'jobs' => ['phpstan-src', 'phpcs', 'phpunit'],
        'options' => [
            'reports' => [
                'sarif'       => 'reports/qa.sarif',
                'junit'       => 'reports/junit.xml',
                'codeclimate' => 'reports/gl-code-quality.json',
            ],
        ],
    ],
],
```

Valid format keys are `json`, `junit`, `sarif`, `codeclimate` (the structured set). Any other key is rejected by `conf:check`. Missing parent directories are created on run.

### CLI flags

The same effect can be obtained per-invocation:

```bash
githooks flow qa \
  --report-sarif=reports/qa.sarif \
  --report-junit=reports/junit.xml \
  --report-codeclimate=reports/gl-code-quality.json
```

### Precedence (per format)

CLI wins over config, **format by format**. Given a config that declares SARIF and JUnit, `--report-sarif=other.sarif` overrides only the SARIF entry; the JUnit one keeps the config value.

### Combining `--format` and `--report-*`

`--format=` keeps governing **stdout** exactly as before. The `--report-*` files are always extra targets:

| Invocation | stdout | Files |
|---|---|---|
| `flow qa` | text summary | — |
| `flow qa --format=json` | JSON | — |
| `flow qa --format=json --output=foo.json` | (silent) | `foo.json` |
| `flow qa --report-sarif=q.sarif` | text summary | `q.sarif` |
| `flow qa --format=json --report-sarif=q.sarif --report-junit=q.xml` | JSON | `q.sarif`, `q.xml` |
| `flow qa --format=sarif --report-sarif=q.sarif` | SARIF | `q.sarif` (same payload twice — different destinations) |

### `--no-reports`

Skips the `reports` section from config without cancelling the CLI `--report-*` flags (PHPUnit `--no-coverage` style). Useful when an external consumer (an AI tool, an ad-hoc script) wants to read JSON cleanly without dropping files declared by the project's config:

```bash
# Read JSON without writing any report file
githooks flow qa --format=json --no-reports

# Same, but still write a single SARIF that the tool needs
githooks flow qa --format=json --no-reports --report-sarif=/tmp/q.sarif
```

See [How-To: CI/CD](../how-to/ci-cd.md) for end-to-end pipeline recipes.
