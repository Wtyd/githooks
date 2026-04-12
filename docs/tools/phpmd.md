# PHP Mess Detector

Detects code quality issues: unused code, overly complex methods, naming violations, and more.

- **Type:** `phpmd`
- **Accelerable:** Yes
- **Default executable:** `vendor/bin/phpmd`

## Keywords

| Keyword | Type | Description | Example |
|---|---|---|---|
| `paths` | Array | Directories to analyze. | `['src']`, `['src', 'app']` |
| `rules` | String | Rulesets (comma-separated) or config file. | `'cleancode,codesize'`, `'myrules.xml'` |
| `exclude` | Array | Paths to exclude. | `['vendor']`, `['vendor', 'tests']` |
| `cache` | Boolean | Enable caching (PHPMD 2.13.0+). | `true`, `false` |
| `cache-file` | String | Custom cache file path. | `'.phpmd.cache'` |
| `cache-strategy` | String | Cache strategy. | `'content'`, `'timestamp'` |
| `suffixes` | String | File suffixes to check. | `'php'`, `'php,inc'` |
| `baseline-file` | String | Baseline file to ignore known violations. | `'phpmd-baseline.xml'` |

Plus all [common keywords](../configuration/jobs.md#common-keywords).

## Examples

Minimal:

```php
'phpmd_src' => [
    'type'  => 'phpmd',
    'paths' => ['src'],
    'rules' => 'cleancode,codesize,naming,unusedcode',
],
```

Full:

```php
'phpmd_src' => [
    'type'           => 'phpmd',
    'executablePath' => 'tools/phpmd',
    'paths'          => ['src'],
    'rules'          => './qa/phpmd-ruleset.xml',
    'exclude'        => ['vendor'],
    'cache'          => true,
    'cache-strategy' => 'content',
    'suffixes'       => 'php',
    'baseline-file'  => 'phpmd-baseline.xml',
],
```

## Cache

Default cache location: `.phpmd.cache` (or the `cache-file` from job config). Cleared with `githooks cache:clear`.
