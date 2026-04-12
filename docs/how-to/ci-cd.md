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

## JSON output for custom processing

```bash
githooks flow qa --format=json
```

The JSON output includes flow name, success status, total time, and per-job results. Useful for custom reporting or feeding results to other tools.

## Dry-run in CI

Use `--dry-run` to verify what commands would run without executing them:

```bash
githooks flow qa --dry-run --format=json
```

The JSON output in dry-run mode includes a `command` field for each job.
