# Psalm

Static analysis tool focused on type safety.

- **Type:** `psalm`
- **Accelerable:** Yes
- **Default executable:** `vendor/bin/psalm`

## Keywords

| Keyword | Type | Description | Example |
|---|---|---|---|
| `config` | String | Path to Psalm XML configuration file. | `'psalm.xml'`, `'qa/psalm.xml'` |
| `memory-limit` | String | PHP memory limit. | `'1G'`, `'512M'` |
| `threads` | Integer | Number of threads for parallel analysis. | `1`, `4`, `8` |
| `output-format` | String | Output format. | `'console'`, `'json'`, `'checkstyle'` |
| `plugin` | String | Path to a Psalm plugin. | `'path/to/plugin.php'` |
| `use-baseline` | String | Baseline file to ignore known issues. | `'psalm-baseline.xml'` |
| `report` | String | Generate a report file (format inferred from extension). | `'psalm-report.xml'` |
| `no-diff` | Boolean | Disable diff mode (analyze all files). | `true`, `false` |
| `paths` | Array | Directories to analyze. | `['src']`, `['src', 'app']` |

Plus all [common keywords](../configuration/jobs.md#common-keywords).

## Examples

Minimal:

```php
'psalm_src' => [
    'type'  => 'psalm',
    'paths' => ['src'],
],
```

Full:

```php
'psalm_src' => [
    'type'          => 'psalm',
    'paths'         => ['src', 'app'],
    'config'        => 'qa/psalm.xml',
    'memory-limit'  => '1G',
    'threads'       => 4,
    'output-format' => 'console',
    'use-baseline'  => 'psalm-baseline.xml',
    'no-diff'       => true,
],
```

## Threading

Psalm supports the `--threads` flag for internal parallelism. When `processes > 1` in flow options, GitHooks adjusts this flag as part of the thread budget distribution.

## Cache

Default cache location: `.psalm/cache/`. Cleared with `githooks cache:clear`.
