# PHP CS Fixer

Automatic coding-style fixer. Applies or checks a set of fixing rules against your codebase.

- **Type:** `php-cs-fixer`
- **Accelerable:** Yes
- **Default executable:** `vendor/bin/php-cs-fixer`
- **Subcommand:** `fix`

## Keywords

| Keyword | Type | Description | Example |
|---|---|---|---|
| `config` | String | Path to PHP CS Fixer configuration file. | `'.php-cs-fixer.php'`, `'qa/.php-cs-fixer.dist.php'` |
| `rules` | String | Rule set to apply (comma-separated or JSON). | `'@PSR12'`, `'@Symfony,-yoda_style'` |
| `dry-run` | Boolean | Do not modify files; report fixes only. | `true`, `false` |
| `diff` | Boolean | Show a diff for each file fixed. | `true`, `false` |
| `allow-risky` | String | Allow risky rules (`'yes'` or `'no'`). | `'yes'`, `'no'` |
| `using-cache` | String | Enable the fixer cache (`'yes'` or `'no'`). | `'yes'`, `'no'` |
| `cache-file` | String | Path to the cache file. | `'.php-cs-fixer.cache'`, `'var/cache/cs-fixer.cache'` |
| `paths` | Array | Directories or files to analyze. | `['src']`, `['src', 'app']` |

Plus all [common keywords](../configuration/jobs.md#common-keywords).

## Examples

Minimal:

```php
'cs_fixer_src' => [
    'type'  => 'php-cs-fixer',
    'paths' => ['src'],
],
```

Full:

```php
'cs_fixer_src' => [
    'type'        => 'php-cs-fixer',
    'paths'       => ['src', 'app'],
    'config'      => 'qa/.php-cs-fixer.dist.php',
    'rules'       => '@PSR12',
    'dry-run'     => false,
    'diff'        => true,
    'allow-risky' => 'yes',
    'using-cache' => 'yes',
    'cache-file'  => '.php-cs-fixer.cache',
],
```

## Fix mode vs dry-run

By default, `php-cs-fixer` modifies files in place. In `--fast` mode GitHooks automatically re-stages modified files (`fixApplied: true` in the JSON output) so fixes are included in the commit.

To run in check-only mode (for CI), set `dry-run: true` or invoke with the CLI flag:

```bash
githooks job cs_fixer_src --dry-run
```

In dry-run mode `fixApplied` is always `false` and the exit code is non-zero if any file would have been changed.

## Cache

Default cache location: `.php-cs-fixer.cache` in the project root (or the path specified by `cache-file`). Cleared with `githooks cache:clear cs_fixer_src`.

## See also

- [Jobs reference](../configuration/jobs.md) — common keywords.
- [Rector](rector.md) — the other automatic refactoring job type shipped in v3.2.
- [`githooks cache:clear`](../cli/cache-clear.md) — clear the fixer cache.
