# PHP Copy Paste Detector

Detects duplicate code in your project.

- **Type:** `phpcpd`
- **Accelerable:** No (needs the full codebase for duplication detection)
- **Default executable:** `vendor/bin/phpcpd`

## Keywords

| Keyword | Type | Description | Example |
|---|---|---|---|
| `paths` | Array | Directories to check. | `['src']`, `['./']` |
| `exclude` | Array | Paths to exclude. | `['vendor']`, `['vendor', 'tests']` |
| `min-lines` | Integer | Minimum identical lines to detect. | `5`, `10` |
| `min-tokens` | Integer | Minimum identical tokens to detect. | `70`, `100` |

Plus all [common keywords](../configuration/jobs.md#common-keywords).

## Examples

Minimal:

```php
'phpcpd_src' => [
    'type'  => 'phpcpd',
    'paths' => ['src'],
],
```

Full:

```php
'phpcpd_src' => [
    'type'       => 'phpcpd',
    'paths'      => ['src'],
    'exclude'    => ['vendor', 'tests'],
    'min-lines'  => 5,
    'min-tokens' => 70,
],
```

## Why not accelerable?

phpcpd detects duplicated code across the entire codebase. Analyzing only staged files would miss duplications between staged and non-staged code.
