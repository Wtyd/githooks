# Output Formats

GitHooks supports three output formats: `text`, `json`, and `junit`.

## Text (default)

Human-readable output with status, time, and error details:

```bash
githooks flow qa
```

```
  parallel_lint - OK. Time: 150ms
  phpcs_src - OK. Time: 890ms
  phpstan_src - KO. Time: 2.34s

  phpstan_src:
    /src/Foo.php:12  Access to undefined property $bar

Results: 2/3 passed in 3.45s
```

## JSON

Machine-readable output for CI pipelines, scripts, or AI tools:

```bash
githooks flow qa --format=json
```

The JSON output includes: flow name, overall success, total time, and an array of job results with name, success, time, output, and fixApplied.

With `--dry-run`, each job result includes a `command` field showing the shell command that would run.

## JUnit

JUnit XML compatible with CI test reporting tools (GitHub Actions, GitLab CI, Jenkins):

```bash
githooks flow qa --format=junit > junit.xml
```

Use with test reporting actions:

```yaml
# GitHub Actions
- run: vendor/bin/githooks flow qa --format=junit > junit.xml
- uses: mikepenz/action-junit-report@v4
  if: always()
  with:
    report_paths: junit.xml
```

## Single job output

The `--format` flag also works with the `job` command:

```bash
githooks job phpstan_src --format=json
githooks job phpcs_src --format=junit
```

## Dry-run

Combine `--dry-run` with any format to see what commands would run:

```bash
githooks flow qa --dry-run                 # text
githooks flow qa --dry-run --format=json   # JSON with command field
```
