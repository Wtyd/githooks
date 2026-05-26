# CI/CD Integration

Use GitHooks in CI pipelines for consistent QA checks across local development and CI.

## Basic GitHub Actions workflow

```yaml
name: QA
on: [push, pull_request]

jobs:
  qa:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
      - run: composer install --no-progress
      - run: vendor/bin/githooks flow qa --format=junit > junit.xml
      - uses: mikepenz/action-junit-report@v4
        if: always()
        with:
          report_paths: junit.xml
```

## CI annotations

GitHooks auto-detects CI environments and emits native annotations:

- **GitHub Actions** (`GITHUB_ACTIONS=true`): wraps each job in `::group::JOB::…::endgroup::`, and parses tool output for `file.php:LINE` patterns to emit `::error file=…,line=…::MESSAGE` annotations that appear inline in the PR diff.
- **GitLab CI** (`GITLAB_CI` env var set): wraps each job in a collapsible `section_start:` / `section_end:` block (see "GitLab CI sections" below for the per-job collapse rules and the dedicated Summary section).

No configuration needed — annotations are on by default in CI. Opt out with `--no-ci`:

```bash
githooks flow qa --no-ci         # plain output even under GITHUB_ACTIONS
```

Annotations stream on **stdout** alongside the normal text output, so they show up directly in the CI log. When using structured formats (`--format=json` etc.), annotations route to **stderr** so they don't contaminate the stdout payload.

### ANSI colour in CI logs

Symfony Console disables ANSI decoration off-TTY by default — every `<fg=red>✗ Flow time-budget exceeded…</>` and `<fg=yellow>⚠ Job exceeded memory threshold…</>` would otherwise be stripped before reaching the log, leaving the operator scanning every row to spot the failure. GitHooks **forces decoration on** when running under GitHub Actions or GitLab CI so:

- `× Flow time-budget exceeded` and `⚠ memory-budget warning` lines render in red / yellow.
- The `--stats` table cells render with colour: `KO` red, `OK ⚠` yellow, `⏭` blue, `TOTAL ✗ / ✔` red / green.
- Symfony Table headers render in green (default Symfony behaviour with decoration on).

Both GitHub Actions and GitLab CI render ANSI in their log viewers. `--no-ci` opts out of the forced decoration alongside the section markers.

### GitLab CI sections

Each job becomes its own collapsible section in the GitLab job log. The decorator buffers the job's body and emits the section **atomically** on close — so even with `processes > 1` and many jobs running in parallel, sections never interleave (which the GitLab `section_start` / `section_end` protocol does not support):

| Outcome | Section flag | Behaviour in the UI |
|---|---|---|
| OK | `[collapsed=true]` | Section appears folded; click to expand. |
| KO | `[collapsed=false]` | Section appears **expanded by default** — the tool's output (violations, stack traces) is visible without manual expansion. |
| Skipped | `[collapsed=true]` | One-line section showing the skip reason. |

After the per-job sections, a final `githooks_summary[collapsed=false]` section wraps the `Results: P/N passed in T` line, the `--stats` table (when active) and any `Report written to: …` notices. It is expanded by default so the run summary is visible at a glance.

```text
> phpcs_src                                            (collapsed, OK)
> phpstan_src                                          (collapsed, OK)
v phpmd_src                                            (auto-expanded, KO)
    --- phpmd_src ---
    9 | VIOLATION | Avoid variables with short names like $a…
    Found 16 violations and 0 errors in 15ms
v Summary                                              (auto-expanded)
    Results: 11/24 passed in 55.63s ✗
    +-----------------+--------+--------+...
    | Job             | Status | Time   |
    | phpstan_src     | KO     | 15.18s |  ← red
    | TOTAL (flow)    | 11/24 ✗| 55.63s |  ← red
    +-----------------+--------+--------+...
```

Symfony Style chips with the live timer (e.g. the `00:00` clock GitLab shows on the right of each section header) and the per-job durations are GitLab's own UI — the decorator only emits the section markers and the body content.

### Human-readable KO body under `--format=codeclimate` / `--format=sarif`

When `--format=codeclimate` or `--format=sarif` is active (CLI flag or `reports.codeclimate` / `reports.sarif` in config), GitHooks reconfigures each tool to emit JSON (`phpstan --error-format=json`, `phpcs --report=json`, `phpmd … json`, `psalm --output-format=json`, `parallel-lint --json`) so the file-based formatters can parse it. The CI display layer translates that JSON back into a human listing (path + indented `line N` / `line N:C` rows + `Totals: X file(s), Y issue(s)`), so the GitLab section / GitHub Actions group / framed-error block keeps showing `file.php:line  message  [rule]` rather than a raw minified JSON blob. The `output` field in JSON v2 still carries the raw tool JSON unchanged — only the **display** layer is humanised. Custom jobs and tools without a registered parser fall back to the raw output, identical to prior behaviour.

## Fast-branch mode for PRs

Analyze only the files that changed in the branch, instead of the full codebase:

```bash
githooks flow qa --fast-branch
```

This computes the diff between the current branch and the main branch, then runs accelerable jobs only against those files. Ideal for PR checks:

```yaml
- run: vendor/bin/githooks flow qa --fast-branch --format=junit > junit.xml
```

!!! note
    In shallow clones (`actions/checkout` with default `fetch-depth: 1`), `--fast-branch` may not be able to compute the diff. Set `fetch-depth: 0` or configure `fast-branch-fallback`:
    ```yaml
    - uses: actions/checkout@v4
      with:
        fetch-depth: 0  # full history for branch diff
    ```

## Structured output in CI

All structured formats route progress to stderr and the payload to stdout, so piping works without contamination:

```bash
githooks flow qa --format=json > report.json   # stdout → file, progress on CI log
```

### JUnit (test reporters)

Covered by the basic workflow above. JUnit is the right choice when you want the failures to appear in the PR checks UI as individual test cases.

### GitLab Code Quality

```yaml
qa:
  script: vendor/bin/githooks flow qa --format=codeclimate --output=gl-code-quality-report.json
  artifacts:
    reports:
      codequality: gl-code-quality-report.json
```

### GitHub Code Scanning

```yaml
- run: vendor/bin/githooks flow qa --format=sarif --output=githooks-results.sarif
  continue-on-error: true
- uses: github/codeql-action/upload-sarif@v3
  if: always()
  with:
    sarif_file: githooks-results.sarif
```

## Multi-report (one runner, several artifacts)

When the pipeline needs **SARIF** for Code Scanning, **JUnit** for the test dashboard and **Code Climate** for the GitLab MR widget, run the flow once and emit every format in parallel via the `--report-*` flags:

```yaml
- run: |
    vendor/bin/githooks flow qa \
      --report-sarif=reports/qa.sarif \
      --report-junit=reports/junit.xml \
      --report-codeclimate=reports/gl-code-quality.json
  continue-on-error: true
- uses: github/codeql-action/upload-sarif@v3
  if: always()
  with:
    sarif_file: reports/qa.sarif
- uses: mikepenz/action-junit-report@v4
  if: always()
  with:
    report_paths: reports/junit.xml
- uses: actions/upload-artifact@v4
  if: always()
  with:
    name: gl-code-quality-report
    path: reports/gl-code-quality.json
```

The same setup with declarative config:

```php
// githooks.php
'flows' => [
    'qa' => [
        'jobs' => ['phpstan-src', 'phpcs', 'phpunit'],
        'options' => [
            'reports' => [
                'sarif'       => 'reports/qa.sarif',
                'junit'       => 'reports/junit.xml',
                'codeclimate' => 'reports/gl-code-quality.json',
            ],
        ],
    ],
],
```

```yaml
- run: vendor/bin/githooks flow qa
  continue-on-error: true
```

`--report-*` flags override the config entry for the same format; other formats keep the config value. See [Configuration / Options / Multi-report](../configuration/options.md#multi-report) for the full precedence table.

## Recipe: one `flows` for CI

A typical CI used to run two `flow` invocations sequentially — one for QA, one for tests — each paying the `composer install` + PHP startup tax. Replace both with a single `flows` invocation backed by a **meta-flow** declared in config:

```php
// githooks.php
'flows' => [
    'options' => ['processes' => 4],

    'qa'       => ['jobs' => ['phpcs_src', 'phpstan_src', 'phpmd_src']],
    'ci-tests' => ['jobs' => ['phpunit_all']],

    'ci-pack' => [
        'flows'   => ['qa', 'ci-tests'],
        'options' => [
            'processes' => 4,
            'fail-fast' => true,
            'reports'   => ['sarif' => 'reports/qa.sarif', 'junit' => 'reports/junit.xml'],
        ],
    ],
],
```

```yaml
# .github/workflows/ci.yml
- run: vendor/bin/githooks flows ci-pack --show-progress
- uses: github/codeql-action/upload-sarif@v3
  if: always()
  with:
    sarif_file: reports/qa.sarif
- uses: mikepenz/action-junit-report@v4
  if: always()
  with:
    report_paths: reports/junit.xml
```

Single PHP runtime, single thread budget, single combined `FlowResult`, two artifacts emitted in parallel. The same `githooks flows ci-pack` runs locally for the developer with no flag changes.

Prefer **declarative meta-flows** to ad-hoc `flows qa ci-tests` invocations: the meta-flow lives with the project, exposes its own options, and produces a stable `flow` identifier for dashboards.

## Dry-run in CI

Use `--dry-run` to verify what commands would run without executing them:

```bash
githooks flow qa --dry-run --format=json
```

The JSON output in dry-run mode includes a `command` field for each job, which is handy for debugging runner configurations or documenting the exact shell command that CI executes.

## See also

- [Output Formats](output-formats.md) — JSON v2 schema, JUnit, Code Climate, SARIF details.
- [`githooks flow`](../cli/flow.md) — CLI reference for flags used above.
- [`githooks flows`](../cli/flows.md) — combined runs and the four invocation modes.
- [Configuration: Flows — Meta-flows](../configuration/flows.md#meta-flows) — declarative composition rules.
- [Execution Modes](../execution-modes.md) — `full`, `fast`, `fast-branch`.
