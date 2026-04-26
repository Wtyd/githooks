# --files / --files-from / --exclude-pattern

`--files` and `--files-from` let you point a flow or a job at an **explicit list of files** instead of deriving it from the staging area (`--fast`) or the diff against the main branch (`--fast-branch`). They cover three real cases:

1. Local validation against a single file (IDE on-save).
2. CI runners with shallow checkouts where `--fast-branch` cannot compute a diff.
3. Custom integrations (manifest produced by another tool, scripts, hook framework).

`--exclude-pattern` filters that input list with glob patterns, so you can pass "everything that changed" and drop tests / generated code in one go.

## Quick start

```bash
# Single file — typical IDE on-save call
githooks job phpstan_src --files=src/User.php

# Several files at once
githooks flow qa --files=src/User.php,src/Order.php

# Manifest produced by git
git diff --name-only origin/main...HEAD > /tmp/changed.txt
githooks flow qa --files-from=/tmp/changed.txt

# Manifest minus tests and generated code
githooks flow qa \
  --files-from=/tmp/changed.txt \
  --exclude-pattern='**/*Test.php,src/Generated/**'
```

## How files mode interacts with jobs

| Job kind | Behaviour with `--files` |
|---|---|
| **Accelerable** (`phpstan`, `phpcs`, `phpcbf`, `phpmd`, `psalm`, `parallel-lint`, `php-cs-fixer`, `rector`, or any custom job with `accelerable: true`) | Runs **only** on the input files that fall inside its configured `paths`. If none match, the job is skipped with reason `"no input files match its paths"`. |
| **Non-accelerable** (`phpunit`, `phpcpd`, `composer-audit`, `composer-update`, `composer-downgrade`, `script`, custom without `accelerable`) | Ignores the input list and runs against its declared `paths`. Same behaviour as `--fast` / `--fast-branch`. |

This mirrors the model of `--fast`: filter the *accelerable* jobs, leave the rest as configured.

## --files

A comma-separated list (CSV). Paths are resolved against **CWD** (the directory you launch `githooks` from), so they line up with `git diff --name-only` output. Absolute paths are accepted as-is. Whitespace is trimmed and duplicates are removed.

If a path points to a **directory**, it is expanded recursively to every `.php` / `.phtml` file underneath. If a path does not exist, you get a warning and that entry is dropped from the input.

## --files-from

A path to a text file with **one path per line**:

- Lines starting with `#` (after trimming) are comments.
- Empty lines are skipped.
- UTF-8 with or without BOM. CRLF line endings are normalised.
- Same dedup / directory-expansion rules as `--files`.

Use `--files-from` whenever the list might be long (the shell `ARG_MAX` is around 128 KB on Linux):

```bash
git diff --name-only origin/main...HEAD > /tmp/changed.txt
githooks flow qa --files-from=/tmp/changed.txt --format=sarif --output=qa.sarif
```

`--files` and `--files-from` are **mutually exclusive**. Passing both fails with exit code 1 and the message:

```
--files and --files-from are mutually exclusive
```

## --exclude-pattern

Comma-separated list of glob patterns to drop from the input list (post-expansion, before per-job filtering):

| Token | Meaning |
|---|---|
| `*` | Anything except `/` |
| `**` | Zero or more directory levels |
| `?` | One character (not `/`) |

Patterns are evaluated against the path **as the user supplied it** (relative to CWD, or absolute when the input was absolute). The check is OR-based — a path is dropped if **any** pattern matches.

```bash
# Run QA on everything that changed except tests
githooks flow qa --files-from=changed.txt --exclude-pattern='**/*Test.php'

# Drop generated code from a directory expansion
githooks flow qa --files=src --exclude-pattern='src/Generated/**'

# Multiple patterns: drop tests AND migrations
githooks flow qa --files-from=changed.txt --exclude-pattern='**/*Test.php,database/migrations/**'
```

`--exclude-pattern` **requires** `--files` or `--files-from`. Used alone:

```
✗ --exclude-pattern requires --files or --files-from
```

If the patterns wipe out the whole list:

```
✗ --exclude-pattern eliminated all input files
```

Patterns that don't match anything are silently ignored — you can keep a long reusable exclude list without warnings.

> **Different from `exclude-files` in hook config.** This flag filters paths used by an `--files` / `--files-from` invocation. The `exclude-files` key inside a hook ref ([Conditional hooks](conditional-hooks.md)) is **gating**: it decides whether a HookRef runs at all based on the staged file set. Same syntax, different role.

## Mixing with --fast / --fast-branch

`--files` / `--files-from` win over the staging-derived modes. If you mix them you get a warning, and the explicit list is used:

```bash
githooks flow qa --files=src/User.php --fast
# ⚠ --files takes precedence over --fast (--fast ignored)
```

`--fast-branch-fallback` is silently ignored when files mode is in effect.

## What the JSON v2 output adds

When files mode is in effect, the JSON v2 payload reports `executionMode: "files"` and adds an `inputFiles` block at the root and on every accelerable job:

```json
{
  "executionMode": "files",
  "inputFiles": {
    "source": "files-from",
    "sourcePath": "/tmp/changed.txt",
    "totalProvided": 12,
    "totalValid": 10,
    "invalid": ["deleted.php"],
    "excludedPatterns": ["**/*Test.php"],
    "excluded": ["tests/UserTest.php", "tests/OrderTest.php"],
    "totalAfterExclude": 8
  },
  "jobs": [
    {
      "name": "phpstan_src",
      "type": "phpstan",
      "inputFiles": {
        "matched": ["src/User.php", "src/Order.php"],
        "matchedCount": 2,
        "totalAvailable": 8
      }
    }
  ]
}
```

`excludedPatterns`, `excluded` and `totalAfterExclude` only appear when `--exclude-pattern` was passed. Non-accelerable jobs do **not** emit a per-job `inputFiles` key (they ran with their original `paths`). When files mode is **not** active, the entire `inputFiles` block — root and per-job — is absent (backward-compatible).

## Configuration: CLI-only

`--files`, `--files-from` and `--exclude-pattern` are exclusively command-line flags. `conf:check` flags any `files` / `files-from` key declared in `flow.options` or in a job as an unknown key. The runtime list of files is volatile by definition; if you want a job to operate only on certain paths, that's what the job's own `paths` key is for.

## See also

- [Execution Modes](../execution-modes.md)
- [`githooks flow`](../cli/flow.md) — full flag reference
- [`githooks job`](../cli/job.md) — single-job runner
- [Conditional hooks](conditional-hooks.md) — `exclude-files` in hook config (different feature, same glob syntax)
