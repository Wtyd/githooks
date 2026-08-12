# Laravel Pint

[Laravel Pint](https://laravel.com/docs/pint) is Laravel's opinionated code-style fixer, built on top of [PHP CS Fixer](phpcsfixer.md) and installed by default in every new Laravel application. GitHooks wraps the `pint` binary as a first-class fixer job — with automatic re-staging of fixes and a check-only `--test` mode for CI — completing the Symfony/Laravel fixer pair (`php-cs-fixer` / `pint`).

- **Type:** `pint`
- **Accelerable:** Yes
- **Default executable:** `vendor/bin/pint`

> Pint is installed in **your project** (`composer require laravel/pint --dev`), not by GitHooks. Like Pest, GitHooks does not ship or self-test with Pint — it only builds and runs its command.

## Keywords

| Keyword | Type | Description | Example |
|---|---|---|---|
| `config` | String | Path to a `pint.json` (`--config`). | `'pint.json'`, `'vendor/my-org/coding-style/pint.json'` |
| `test` | Boolean | Check mode (`--test`): report style issues without fixing them; the job fails if any are found. | `true`, `false` |
| `paths` | Array | Directories or files to fix. | `['app']`, `['app', 'tests']` |

Plus all [common keywords](../configuration/jobs.md#common-keywords).

> Pint silently ignores a `--config` path that does not exist (it falls back to its defaults and still exits 0), so a typo in `config` will not fail the job — double-check the path.

## Examples

Minimal (fix mode, re-stages fixes on commit):

```php
'pint' => [
    'type'  => 'pint',
    'paths' => ['app', 'tests'],
],
```

Check-only (CI):

```php
'pint-check' => [
    'type'   => 'pint',
    'config' => 'pint.json',
    'test'   => true,
    'paths'  => ['app', 'tests'],
],
```

## Fix mode vs `--test`

Exit-code semantics (verified against Pint 1.30 — they do **not** mirror php-cs-fixer's bit flags despite the shared engine):

| Mode | Clean | Style issues | Parse error |
|---|---|---|---|
| `pint` (fix) | 0 | 0 (fixes applied) | 1 (parseable files still fixed) |
| `pint --test` | 0 | 1 | 1 |

In fix mode, GitHooks automatically re-stages the staged files Pint rewrote (`fixApplied: true` in the JSON output) so the fixes are included in the commit — scoped to the files the tool changed, like every native fixer. With `test: true`, `fixApplied` is always `false`, nothing is rewritten, and the job fails when the style is dirty.

## File selection: GitHooks, not `--dirty`

Pint's own `--dirty` flag (fix only uncommitted files) is deliberately not mapped: file selection is governed by GitHooks — in [`--fast` mode](../execution-modes.md) the staged files under `paths` are injected as explicit arguments — and two competing selection mechanisms would blur which files actually ran.

## Cache

Pint keeps its cache in the system temp directory, never in the project — there is nothing for [`githooks cache:clear`](../cli/cache-clear.md) to clear.

## See also

- [PHP CS Fixer](phpcsfixer.md) — the engine Pint is built on, and Symfony's de facto fixer.
- [Custom jobs → Auto-staging fixes](custom.md#auto-staging-fixes-re-stage) — the same re-stage for non-native fixers.
- [Jobs reference](../configuration/jobs.md) — common keywords.
