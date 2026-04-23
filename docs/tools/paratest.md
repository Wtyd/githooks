# Paratest

Parallel driver for PHPUnit. Runs test suites across multiple worker processes, reusing PHPUnit's CLI.

- **Type:** `paratest`
- **Accelerable:** No (runs tests, not source files — inherited from [PHPUnit](phpunit.md))
- **Default executable:** `vendor/bin/paratest`

## Keywords

Paratest inherits every keyword from [PHPUnit](phpunit.md) and adds one for worker control:

| Keyword | Type | Description | Example |
|---|---|---|---|
| `processes` | Integer | Number of worker processes. Emitted as `--processes=N`. | `4`, `8` |
| `config` | String | Path to PHPUnit XML configuration file. | `'phpunit.xml'` |
| `configuration` | String | Alias for `config`. | `'phpunit.xml'` |
| `group` | String | Run only tests from specified group(s). | `'integration'` |
| `exclude-group` | String | Exclude tests from specified group(s). | `'slow'` |
| `filter` | String | Filter which tests to run by regex pattern. | `'testSomething'` |
| `log-junit` | String | Log test execution in JUnit XML format. | `'junit.xml'` |

Plus all [common keywords](../configuration/jobs.md#common-keywords), including [`cores`](../configuration/jobs.md#reserving-cores-explicitly-cores).

## Examples

Minimal:

```php
'paratest_all' => [
    'type'  => 'paratest',
    'cores' => 4,   // reserves 4 cores + passes --processes=4 to paratest
],
```

With PHPUnit configuration and filter:

```php
'paratest_integration' => [
    'type'          => 'paratest',
    'configuration' => 'tests/phpunit.integration.xml',
    'group'         => 'integration',
    'cores'         => 8,
],
```

## `cores` vs `processes`

The `cores` keyword is the recommended way to declare paratest parallelism because it does two things at once:

1. Reserves N cores in the [thread budget](../configuration/options.md#thread-budget) so the allocator knows what paratest will consume.
2. Passes `--processes=N` to paratest so the worker count matches the reservation.

If you set both `cores` and `processes`, `cores` wins at runtime and [`conf:check`](../cli/conf-check.md) emits a warning. See [Reserving cores explicitly](../configuration/jobs.md#reserving-cores-explicitly-cores).

## Why use paratest instead of phpunit?

Paratest is drop-in for most PHPUnit suites and gives linear speed-ups on test-heavy codebases. The main gotchas:

- Tests that share global state (static singletons, shared filesystem state, non-isolated databases) may fail in parallel. Run them in a separate sequential flow or exclude them with `exclude-group`.
- Paratest uses its own runner; make sure your `phpunit.xml` bootstrap is compatible.

See the [Paratest documentation](https://github.com/paratestphp/paratest) for installation and advanced configuration.

## Cache

Default cache location: `.phpunit.result.cache` (inherited from PHPUnit). Cleared with `githooks cache:clear`.
