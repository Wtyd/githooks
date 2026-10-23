# Finding Flaky Tests

A flaky test passes on one run and fails on the next with no code change in between: random data from factories, shared state between parallel workers, timing, ordering. It usually shows up as a red CI job that someone re-runs until it goes green, and nobody learns *which* test it was.

`alfil flow <name> --repeat=N` runs the flow N times, reads the junit file each test job writes, and crosses the results **test by test**. A test that passed every time is stable, one that failed every time is broken, and one that did both is flaky.

!!! important "Repeat, not retry"
    `--repeat` always runs the N passes, whatever their outcome, and reports what changed. It never re-runs a failure until it passes: that would hide the flaky test instead of finding it.

## 1. Tell each test job where its junit file is

`--repeat` reads the junit XML your test runner already knows how to write. Declare its path with the `junit` key:

```php
'jobs' => [
    'tests' => [
        'type'   => 'custom',
        'script' => 'php artisan test --parallel --log-junit=reports/junit.xml',
        'junit'  => 'reports/junit.xml',
    ],
],
```

`phpunit` and `paratest` jobs that already declare `log-junit` need nothing else: that path is used when `junit` is absent. A `pest` job, or any `custom` job, needs `junit` — Alfil cannot guess where the tool writes.

A relative path is resolved from the directory you run Alfil in. Each job needs its own path: two jobs writing the same file would overwrite each other's results, so `--repeat` refuses to start.

## 2. Run the flow N times

A flow made of the test jobs only keeps the passes short. `--only-jobs` does the same on an existing flow.

```bash
alfil flow tests --repeat=10
alfil flow qa --repeat=10 --only-jobs=tests
```

Every pass runs, even after a failure. Before each pass, the declared junit files are deleted, so a pass that crashes without writing one is reported as such and never read as the previous pass's results.

## 3. Read the report

```text
Pass 1/10: jobs 1 passed, 0 failed, 0 skipped (41.02s)
Pass 2/10: jobs 0 passed, 1 failed, 0 skipped (40.87s)
…

Flaky test report: flow 'tests', 10 passes, flap threshold 1

  FLAKY     7/10 passed  Tests\Feature\OrderTest::it_applies_the_discount  (tests)
  FAILING   0/10 passed  Tests\Unit\PriceTest::it_rounds_half_up  (tests)

Summary: 812 stable, 1 flaky, 0 suspect, 1 failing, 3 always skipped.
```

| Class | Meaning | Fails the command |
|---|---|---|
| `stable-pass` | Passed every time it ran. | No |
| `flaky` | Both passed and failed, and the rarer outcome reached the threshold. | **Yes** |
| `suspect` | Both passed and failed, below the threshold. Listed so you can keep an eye on it. | No |
| `stable-fail` | Failed every time it ran: a real failure, not a flaky one. | **Yes** |
| `always-skipped` | Skipped (or incomplete) every time. | No |

A `skipped` or `incomplete` outcome is neither a pass nor a failure. `stable-pass` and `stable-fail` get a `-with-skips` variant when some passes skipped the test or did not report it at all.

**Warnings** list what the counts leave out: a pass whose junit file was not written or could not be parsed, a test reported twice in the same file (the worst outcome is kept), and a job that produced no readable junit file in any pass — that one fails the command, because nothing was measured.

## The flap threshold

By default one occurrence is enough: a test that passed 9 times and failed once is flaky. When a suite carries known noise, raise the bar with `--flap-threshold=N`: the rarer outcome must happen at least N times for the test to count as flaky; below that it is listed as `suspect` and does not fail the command.

```bash
alfil flow tests --repeat=20 --flap-threshold=3
```

The threshold is a count, not a percentage, so its meaning does not change with the number of passes.

## Machine-readable report

`--format=json` prints the report as one JSON document (its own schema, `version: 1`), and `--output=PATH` writes it to a file:

```bash
alfil flow tests --repeat=10 --format=json --output=reports/flaky.json
jq -r '.tests[] | select(.class == "flaky") | .test' reports/flaky.json
```

```json
{
    "version": 1,
    "flow": "tests",
    "repeat": 10,
    "threshold": 1,
    "success": false,
    "summary": { "stable": 812, "flaky": 1, "suspect": 0, "failing": 1, "alwaysSkipped": 3 },
    "tests": [
        { "job": "tests", "test": "Tests\\Feature\\OrderTest::it_applies_the_discount", "class": "flaky",
          "passed": 7, "failed": 3, "skipped": 0, "absent": 0 }
    ],
    "jobs": [
        { "name": "tests", "junit": "reports/junit.xml", "passed": 7, "failed": 3, "skipped": 0, "readablePasses": 10 }
    ],
    "anomalies": [],
    "collisions": [],
    "inconclusiveJobs": []
}
```

`tests` lists every test with its counts; `anomalies` lists the passes left out (`missing`, `corrupt`, or `stale` when the previous file could not be removed). The other formats (`junit`, `sarif`, `codeclimate`, `claude-code`) describe a single run and are refused with `--repeat`.

## What `--repeat` ignores

`--save-history`, `--monitor`, `--diag`, `--report-*` and the `reports` section describe one run, so a repeated run neither writes them nor prints them; a warning on stderr names the flags you passed. `--dry-run` shows the plan once and does not repeat anything.

## See also

- [`alfil flow`](../cli/flow.md) — every option of the command.
- [Jobs: common keywords](../configuration/jobs.md#common-keywords) — the `junit` key.
- [PHPUnit](../tools/phpunit.md) and [Paratest](../tools/paratest.md) — `log-junit`.
