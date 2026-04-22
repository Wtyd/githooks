# Rector

Automated refactoring tool. Applies rule sets to upgrade PHP versions, migrate between frameworks, and enforce project-wide coding patterns.

- **Type:** `rector`
- **Accelerable:** Yes
- **Default executable:** `vendor/bin/rector`
- **Subcommand:** `process`

## Keywords

| Keyword | Type | Description | Example |
|---|---|---|---|
| `config` | String | Path to Rector configuration file. | `'rector.php'`, `'qa/rector.php'` |
| `dry-run` | Boolean | Do not modify files; report changes only. | `true`, `false` |
| `clear-cache` | Boolean | Clear Rector's cache before running. | `true`, `false` |
| `no-progress-bar` | Boolean | Suppress the progress bar (recommended in CI). | `true`, `false` |
| `paths` | Array | Directories or files to refactor. | `['src']`, `['src', 'app']` |

Plus all [common keywords](../configuration/jobs.md#common-keywords).

## Examples

Minimal:

```php
'rector_src' => [
    'type'  => 'rector',
    'paths' => ['src'],
],
```

Full:

```php
'rector_src' => [
    'type'            => 'rector',
    'paths'           => ['src', 'app'],
    'config'          => 'qa/rector.php',
    'dry-run'         => false,
    'clear-cache'     => false,
    'no-progress-bar' => true,
],
```

## Refactor mode vs dry-run

By default, `rector` applies refactorings in place. In `--fast` mode GitHooks re-stages modified files (`fixApplied: true` in the JSON output) so refactorings are included in the commit.

To run in check-only mode (for CI), set `dry-run: true` or invoke with the CLI flag:

```bash
githooks job rector_src --dry-run
```

In dry-run mode `fixApplied` is always `false` and the exit code is non-zero if any file would have been changed.

## Cache

Default cache location: `/tmp/rector` (Rector default). Cleared with `githooks cache:clear rector_src` or by setting `clear-cache: true`.

## See also

- [Jobs reference](../configuration/jobs.md) — common keywords.
- [PHP CS Fixer](phpcsfixer.md) — companion coding-style fixer shipped in v3.2.
- [`githooks cache:clear`](../cli/cache-clear.md) — clear the Rector cache.
