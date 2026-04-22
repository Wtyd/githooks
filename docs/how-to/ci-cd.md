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
- **GitLab CI** (`GITLAB_CI` env var set): wraps job output in `section_start:` / `section_end:` markers so the job log in the GitLab UI shows collapsible sections per job.

No configuration needed — annotations are on by default in CI. Opt out with `--no-ci`:

```bash
githooks flow qa --no-ci         # plain output even under GITHUB_ACTIONS
```

Annotations stream on **stdout** alongside the normal text output, so they show up directly in the CI log. When using structured formats (`--format=json` etc.), annotations route to **stderr** so they don't contaminate the stdout payload.

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
  script: vendor/bin/githooks flow qa --format=codeclimate
  artifacts:
    reports:
      codequality: gl-code-quality-report.json
```

### GitHub Code Scanning

```yaml
- run: vendor/bin/githooks flow qa --format=sarif
  continue-on-error: true
- uses: github/codeql-action/upload-sarif@v3
  if: always()
  with:
    sarif_file: githooks-results.sarif
```

## Dry-run in CI

Use `--dry-run` to verify what commands would run without executing them:

```bash
githooks flow qa --dry-run --format=json
```

The JSON output in dry-run mode includes a `command` field for each job, which is handy for debugging runner configurations or documenting the exact shell command that CI executes.

## See also

- [Output Formats](output-formats.md) — JSON v2 schema, JUnit, Code Climate, SARIF details.
- [`githooks flow`](../cli/flow.md) — CLI reference for flags used above.
- [Execution Modes](../execution-modes.md) — `full`, `fast`, `fast-branch`.
