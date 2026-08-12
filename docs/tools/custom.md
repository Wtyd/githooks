# Custom Jobs

The `custom` type makes GitHooks adaptable to any scenario: run tools not natively supported, scripts in any language, or replace removed tools.

- **Type:** `custom`
- **Accelerable:** Opt-in (`accelerable: true` in structured mode)

## Simple mode

Use the `script` key for the full command. Simple and direct, but does not support `--fast` acceleration.

```php
'composer_audit' => [
    'type'   => 'custom',
    'script' => 'composer audit',
],

'backend_tests' => [
    'type'   => 'custom',
    'script' => 'vendor/bin/phpunit --colors true --exclude-group quarantine',
],
```

`other-arguments` is also honored in simple mode: it is appended after the
`script`. This pairs well with `extends` to share a base command across
variants without repeating it — for example, sharding a test suite:

```php
'jest_base'       => ['type' => 'custom', 'script' => 'yarn tests:ci'],
'jest_ci_shard_1' => ['extends' => 'jest_base', 'other-arguments' => '--shard 1/3'],
'jest_ci_shard_2' => ['extends' => 'jest_base', 'other-arguments' => '--shard 2/3'],
'jest_ci_shard_3' => ['extends' => 'jest_base', 'other-arguments' => '--shard 3/3'],
```

Each shard runs `yarn tests:ci --shard N/3`.

## Structured mode (with paths)

Use `executable-path` + `paths` + optional `other-arguments`. This mode supports `--fast` acceleration when `accelerable: true`.

```php
'eslint_src' => [
    'type'             => 'custom',
    'executable-path'  => 'npx eslint',
    'paths'            => ['resources/js'],
    'other-arguments'  => '--fix',
    'accelerable'      => true,
],
```

In normal mode, this runs: `npx eslint resources/js --fix`. With `--fast`, it runs against only the staged files within `resources/js/` instead of the entire directory.

## Auto-staging fixes (`re-stage`)

Fixer tools rewrite files on disk. In a `pre-commit` hook (which runs in `--fast` mode) you usually want those fixes **added back to the commit** automatically — exactly what the native fixer types (`phpcbf`, `php-cs-fixer`, `rector`) do. A plain `custom` job does not, so you would have to append `&& git add ...` to the `script` by hand.

Set `re-stage: true` so a successful run (exit code **0**) is treated as a fix and its changes to the staged files are re-staged automatically:

```php
'pint' => [
    'type'     => 'custom',
    'script'   => 'vendor/bin/pint',
    're-stage' => true,
],
```

- Only a **zero exit code** re-stages. A non-zero exit means the tool failed: nothing is re-staged and the job fails (unless `ignore-errors-on-exit`). `re-stage` never turns a failure into a success.
- Opt-in: without `re-stage` (or with `re-stage: false`) a `custom` job never re-stages — the right default for linters/checkers.
- Use it only with **fixers**. Re-staging covers the staged files **the job rewrote while it ran** — files it never touched keep whatever you deliberately left unstaged (the same behaviour as the native fixer types). Note that `git add` cannot stage part of a file: if the tool rewrites a file that *also* carried unstaged edits of yours, those edits are staged along with the fix.

## Keywords

| Keyword | Mode | Description |
|---|---|---|
| `script` | Simple | The full command to execute. Required if `executable-path` is not set. |
| `executable-path` | Structured | Path to the executable. Required if `script` is not set. |
| `paths` | Structured | Directories or files to analyze. |
| `other-arguments` | Both | Extra CLI flags. Appended after `paths` in structured mode, after the `script` in simple mode. |
| `accelerable` | Structured | Boolean. Opt-in for `--fast` path filtering. Default `false`. |
| `execution` | Both | Per-job execution mode override (`full`, `fast`, `fast-branch`). |
| `ignore-errors-on-exit` | Both | Job returns exit 0 even with problems. |
| `fail-fast` | Both | Stop remaining jobs if this one fails. |
| `re-stage` | Both | Boolean. When the job exits 0, re-stage the fixed files (for fixers like Pint). Default `false`. |

!!! note
    The legacy camelCase keys (`executablePath`, `otherArguments`, `ignoreErrorsOnExit`, `failFast`) are still accepted in v3.3 with a deprecation warning. They will be removed in v4.0. See [v3.3 deprecations](../migration/v33-deprecations.md).

## The `script` type

A minimal sibling of `custom`: one executable plus fixed arguments, with no path handling.

- **Type:** `script`
- **Accelerable:** No

```php
'tests_shard_1' => [
    'type'            => 'script',
    'executable-path' => './run-tests',
    'other-arguments' => '--shard 1/2',
],
```

The command is `executable-path` + `other-arguments` (plus anything after `--` when run via `githooks job`). `executable-path` is **required**: without it the job would build an empty command and be reported as passing while running nothing, so since 3.7 it is rejected as a configuration error.

How it differs from `custom`:

| | `script` | `custom` |
|---|---|---|
| Command source | `executable-path` + `other-arguments` | `script` verbatim (simple mode) or `executable-path` + `paths` + `other-arguments` (structured mode) |
| `paths` / `--fast` | Not supported | Structured mode, opt-in via `accelerable` |
| `re-stage` | Not supported | Supported |

The [common keywords](../configuration/jobs.md#common-keywords) (`executable-prefix`, `ignore-errors-on-exit`, `fail-fast`, `cores`, `warn-after`/`fail-after`, `memory`) apply as in any other type.

For new configurations prefer `custom`, which covers the same case (simple mode) and more. `script` remains useful for its minimal shape: several jobs sharing one runner with different arguments via [`extends`](../configuration/jobs.md), each reported under its own job name.

## Examples

### Run a shell script

```php
'deploy_check' => [
    'type'   => 'custom',
    'script' => 'bash scripts/check-deploy.sh',
],
```

### ESLint with acceleration

```php
'eslint_src' => [
    'type'             => 'custom',
    'executable-path'  => 'npx eslint',
    'paths'            => ['resources/js'],
    'other-arguments'  => '--fix',
    'accelerable'      => true,
],
```

### Prettier

```php
'prettier' => [
    'type'             => 'custom',
    'executable-path'  => 'npx prettier',
    'paths'            => ['resources/js', 'resources/css'],
    'other-arguments'  => '--check',
    'accelerable'      => true,
],
```

### Composer audit (replaces security-checker)

```php
'composer_audit' => [
    'type'   => 'custom',
    'script' => 'composer audit',
],
```
