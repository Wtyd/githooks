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
| `phpcpd` | [PHP Copy Paste Detector](../tools/phpcpd.md) | No |
| `custom` | [Any command](../tools/custom.md) | Opt-in |

See the [Tools Reference](../tools/index.md) for the full keyword documentation of each type.

## Common keywords

The following keywords are available for all job types (except `custom`, which has its own set):

| Keyword | Type | Description |
|---|---|---|
| `type` | String | Determines the tool and accepted keywords. **Mandatory.** |
| `executablePath` | String | Path to the tool binary. If omitted, auto-detects `vendor/bin/{tool}`, then falls back to system PATH. |
| `paths` | Array | Directories or files to analyze. |
| `otherArguments` | String | Extra CLI flags not natively supported by GitHooks. |
| `ignoreErrorsOnExit` | Boolean | Job returns exit 0 even with problems. Default `false`. |
| `failFast` | Boolean | Stop remaining jobs in the flow if this one fails. Default `false`. |
| `accelerable` | Boolean | Override `--fast` behavior. Default depends on type. |
| `execution` | String | Per-job execution mode override: `full`, `fast`, or `fast-branch`. |
| `executable-prefix` | String | Per-job prefix override. Set to `null` or `''` to opt out of the global prefix. |

!!! tip
    Missing keys can cause the job to fail at runtime. For example, a `phpcs` job without `standard` may fail if no standard is configured in the tool's own config file.

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
