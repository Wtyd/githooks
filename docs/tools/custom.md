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
