# PHPUnit

Unit and integration testing framework.

- **Type:** `phpunit`
- **Accelerable:** No (runs tests, not source files)
- **Default executable:** `vendor/bin/phpunit`

## Keywords

| Keyword | Type | Description | Example |
|---|---|---|---|
| `config` | String | Path to PHPUnit XML configuration file. | `'phpunit.xml'`, `'tests/phpunit.xml'` |
| `configuration` | String | Alias for `config`. | `'phpunit.xml'` |
| `group` | String | Run only tests from specified group(s). | `'integration'`, `'unit,fast'` |
| `exclude-group` | String | Exclude tests from specified group(s). | `'slow'`, `'quarantine'` |
| `filter` | String | Filter which tests to run by regex pattern. | `'testSomething'`, `'MyClass'` |
| `log-junit` | String | Log test execution in JUnit XML format. | `'junit.xml'` |

Plus all [common keywords](../configuration/jobs.md#common-keywords).

## Examples

Minimal:

```php
'phpunit_all' => [
    'type'   => 'phpunit',
    'config' => 'phpunit.xml',
],
```

With groups:

```php
'phpunit_fast' => [
    'type'          => 'phpunit',
    'config'        => 'phpunit.xml',
    'exclude-group' => 'slow,quarantine',
],
```

## Why not accelerable?

PHPUnit runs test files, not source files. The staged files are typically source code, not tests. Running only "staged tests" would miss regressions caused by source changes.

If you want faster feedback, use `group` / `exclude-group` to split your test suite into fast and slow groups.

## Cache

Default cache location: `.phpunit.result.cache`. Cleared with `githooks cache:clear`.
