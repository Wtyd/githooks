# Pest

[Pest](https://pestphp.com) is the elegant, PHPUnit-based testing framework and Laravel's default test runner. GitHooks wraps the `pest` binary (or `php artisan test`) so it is a first-class job instead of a `custom` script — with exit-code pass/fail, thread-budget-aware `--parallel`, and Pest's coverage/mutation flags.

- **Type:** `pest`
- **Accelerable:** No (runs tests, not source files — inherited from [PHPUnit](phpunit.md))
- **Default executable:** `vendor/bin/pest`

> Pest is installed in **your project** (`composer require pestphp/pest --dev`), not by GitHooks. Unlike the other tools, GitHooks does not ship or self-test with Pest — it only builds and runs its command.

## Runner: `binary` vs `artisan`

| `runner` | Command | When |
|---|---|---|
| `binary` (default) | `vendor/bin/pest …` | Plain Pest projects. |
| `artisan` | `php artisan test …` | Laravel apps — boots the framework test environment. |

An explicit `executable-path` always wins over `runner`.

## Keywords

| Keyword | Type | Description | Example |
|---|---|---|---|
| `runner` | String | `binary` (default) or `artisan`. | `'artisan'` |
| `parallel` | Boolean | Run tests in parallel (`--parallel`). | `true` |
| `processes` | Integer | Worker count (`--processes=N`); only with `parallel`. Prefer [`cores`](../configuration/jobs.md#reserving-cores-cores-or-the-tools-native-flag). | `4` |
| `coverage` | Boolean | Enable coverage (`--coverage`). | `true` |
| `min` | Integer | Minimum coverage/mutation threshold (`--min=N`). | `90` |
| `only-covered` | Boolean | Hide files with zero coverage (`--only-covered`). | `true` |
| `mutate` | Boolean | Run mutation testing (`--mutate`). | `true` |
| `covered-only` | Boolean | Mutate only covered lines (`--covered-only`). | `true` |
| `bail` | Boolean | Stop on the first failure/untested mutation (`--bail`). | `true` |
| `compact` | Boolean | Compact printer (`--compact`). | `true` |
| `config` / `configuration` | String | Path to the PHPUnit XML config (`-c`). | `'phpunit.xml'` |
| `group` / `exclude-group` / `filter` | String | Inherited from PHPUnit. | `'integration'` |
| `paths` | Array | Test directories/files to run. | `['tests/Unit']` |

Plus all [common keywords](../configuration/jobs.md#common-keywords), including [`cores`](../configuration/jobs.md#reserving-cores-cores-or-the-tools-native-flag).

> Flags are flat: use `coverage: true` **and** `min: 90` (there is no nested `coverage: {min: 90}`).

## Examples

Minimal (Laravel):

```php
'pest' => [
    'type'   => 'pest',
    'runner' => 'artisan',
],
```

Parallel with a coverage gate:

```php
'pest-suite' => [
    'type'     => 'pest',
    'parallel' => true,
    'cores'    => 4,          // reserves 4 cores + passes --parallel --processes=4
    'coverage' => true,
    'min'      => 90,
],
```

Mutation testing on a subset:

```php
'pest-mutate' => [
    'type'         => 'pest',
    'mutate'       => true,
    'covered-only' => true,
    'min'          => 80,
    'paths'        => ['app/Domain'],
],
```

## Parallelism

Pest parallelises with `--parallel --processes=N`. As with [Paratest](paratest.md), declare `cores: N` (or `parallel: true` + `cores: N`) so the [thread budget](../configuration/options.md#thread-budget) reserves the right amount and passes the matching `--processes` to Pest. Without `parallel`, Pest runs single-process: neither `processes` nor `cores` emits a `--processes` flag, since Pest cannot honour it on its own.

## Pass/fail

The verdict comes from Pest's **exit code** (0 = pass). Coverage/mutation thresholds (`--min`) make Pest exit non-zero when unmet, which GitHooks reports as a failed job — no output parsing involved.

## Cache

Default cache location: `.phpunit.result.cache` (inherited from PHPUnit). Cleared with `githooks cache:clear`.
