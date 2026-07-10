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
- Use it only with **fixers**. Re-staging runs `git add` over the whole index, so it also captures unrelated working-tree edits to files that were already staged (the same behaviour as the native fixer types).

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
