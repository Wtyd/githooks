# PHP CodeSniffer (phpcs / phpcbf)

Code style checking (`phpcs`) and auto-fixing (`phpcbf`). Both types share the same keywords.

- **Types:** `phpcs`, `phpcbf`
- **Accelerable:** Yes
- **Default executable:** `vendor/bin/phpcs` / `vendor/bin/phpcbf`

## Keywords

| Keyword | Type | Description | Example |
|---|---|---|---|
| `standard` | String | Ruleset or configuration file. | `'PSR12'`, `'Squiz'`, `'myrules.xml'` |
| `ignore` | Array | Paths to exclude. | `['vendor']`, `['vendor', 'tools']` |
| `error-severity` | Integer | Error severity level to report. | `1`, `5` |
| `warning-severity` | Integer | Warning severity level to report. | `5`, `7` |
| `cache` | Boolean | Enable result caching. | `true`, `false` |
| `no-cache` | Boolean | Disable caching (overrides `cache`). | `true`, `false` |
| `report` | String | Report format. | `'summary'`, `'json'`, `'checkstyle'` |
| `parallel` | Integer | Number of parallel processes. | `2`, `4` |
| `paths` | Array | Directories to check. | `['src']`, `['src', 'tests']` |

Plus all [common keywords](../configuration/jobs.md#common-keywords).

!!! note
    The v2.x `usePhpcsConfiguration` option is no longer supported. Configure `phpcbf` jobs with their own explicit keywords.

## Examples

Minimal:

```php
'phpcs_src' => [
    'type'     => 'phpcs',
    'paths'    => ['src'],
    'standard' => 'PSR12',
],
```

With inheritance for phpcbf:

```php
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
```

## Phpcbf auto-staging

When `phpcbf` fixes files during a `pre-commit` hook run (exit code 1 = fixes applied), GitHooks automatically re-stages the fixed files. This ensures the commit includes the corrected code, not the pre-fix version. Deleted files are excluded from re-staging. No configuration needed.

## Threading

phpcs/phpcbf support the `--parallel` flag for internal parallelism. When `processes > 1` in flow options, GitHooks adjusts this flag as part of the thread budget distribution.

## Cache

Default cache location: `.phpcs.cache`. Cleared with `githooks cache:clear`.
