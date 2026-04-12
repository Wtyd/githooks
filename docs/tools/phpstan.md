# PHPStan

Static analysis tool for PHP. Finds bugs without running the code.

- **Type:** `phpstan`
- **Accelerable:** Yes
- **Default executable:** `vendor/bin/phpstan`
- **Subcommand:** `analyse`

## Keywords

| Keyword | Type | Description | Example |
|---|---|---|---|
| `config` | String | Path to configuration file. | `'phpstan.neon'`, `'qa/phpstan.neon'` |
| `level` | Integer | Analysis level 0-10. Default 0. | `0`, `5`, `8`, `9` |
| `memory-limit` | String | PHP memory limit. | `'1G'`, `'512M'` |
| `error-format` | String | Output format. | `'table'`, `'json'`, `'github'` |
| `no-progress` | Boolean | Suppress progress output. | `true`, `false` |
| `clear-result-cache` | Boolean | Clear result cache before analysis. | `true`, `false` |
| `paths` | Array | Directories to analyze. | `['src']`, `['src', 'app']` |

Plus all [common keywords](../configuration/jobs.md#common-keywords).

## Examples

Minimal:

```php
'phpstan_src' => [
    'type'  => 'phpstan',
    'paths' => ['src'],
    'level' => 8,
],
```

Full:

```php
'phpstan_src' => [
    'type'               => 'phpstan',
    'paths'              => ['src', 'app'],
    'config'             => 'qa/phpstan.neon',
    'level'              => 8,
    'memory-limit'       => '1G',
    'error-format'       => 'table',
    'no-progress'        => true,
    'clear-result-cache' => false,
],
```

## Threading

PHPStan worker count is read from the `.neon` config file (`maximumNumberOfProcesses`). It is not adjustable at runtime via GitHooks, but it is accounted for in the thread budget calculation.

## Cache

Default cache location: `{sys_get_temp_dir}/phpstan/` or the `tmpDir` specified in the `.neon` config. Cleared with `githooks cache:clear`.
