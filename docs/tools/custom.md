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

## Structured mode (with paths)

Use `executablePath` + `paths` + optional `otherArguments`. This mode supports `--fast` acceleration when `accelerable: true`.

```php
'eslint_src' => [
    'type'           => 'custom',
    'executablePath' => 'npx eslint',
    'paths'          => ['resources/js'],
    'otherArguments' => '--fix',
    'accelerable'    => true,
],
```

In normal mode, this runs: `npx eslint resources/js --fix`. With `--fast`, it runs against only the staged files within `resources/js/` instead of the entire directory.

## Keywords

| Keyword | Mode | Description |
|---|---|---|
| `script` | Simple | The full command to execute. Required if `executablePath` is not set. |
| `executablePath` | Structured | Path to the executable. Required if `script` is not set. |
| `paths` | Structured | Directories or files to analyze. |
| `otherArguments` | Structured | Extra CLI flags appended after paths. |
| `accelerable` | Structured | Boolean. Opt-in for `--fast` path filtering. Default `false`. |
| `execution` | Both | Per-job execution mode override (`full`, `fast`, `fast-branch`). |
| `ignoreErrorsOnExit` | Both | Job returns exit 0 even with problems. |
| `failFast` | Both | Stop remaining jobs if this one fails. |

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
    'type'           => 'custom',
    'executablePath' => 'npx eslint',
    'paths'          => ['resources/js'],
    'otherArguments' => '--fix',
    'accelerable'    => true,
],
```

### Prettier

```php
'prettier' => [
    'type'           => 'custom',
    'executablePath' => 'npx prettier',
    'paths'          => ['resources/js', 'resources/css'],
    'otherArguments' => '--check',
    'accelerable'    => true,
],
```

### Composer audit (replaces security-checker)

```php
'composer_audit' => [
    'type'   => 'custom',
    'script' => 'composer audit',
],
```
