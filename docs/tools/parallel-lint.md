# Parallel Lint

Fast PHP syntax checking. Detects syntax errors without executing the code.

- **Type:** `parallel-lint`
- **Accelerable:** Yes
- **Default executable:** `vendor/bin/parallel-lint`

## Keywords

| Keyword | Type | Description | Example |
|---|---|---|---|
| `paths` | Array | Directories to check. | `['./']`, `['src', 'app']` |
| `exclude` | Array | Paths to exclude. | `['vendor']`, `['vendor', 'tools']` |
| `jobs` | Integer | Number of parallel jobs (`-j` flag). | `4`, `10` |

Plus all [common keywords](../configuration/jobs.md#common-keywords).

## Examples

Minimal:

```php
'parallel_lint' => [
    'type'    => 'parallel-lint',
    'paths'   => ['src'],
    'exclude' => ['vendor'],
],
```

Full:

```php
'parallel_lint' => [
    'type'    => 'parallel-lint',
    'paths'   => ['src', 'app', 'config'],
    'exclude' => ['vendor', 'tools'],
    'jobs'    => 10,
],
```

## Threading

Parallel-lint supports the `-j` flag for internal parallelism. When `processes > 1` in flow options, GitHooks adjusts this flag as part of the thread budget distribution.
