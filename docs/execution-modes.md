# Execution Modes

GitHooks supports five execution modes that control which files accelerable jobs analyze.

## Modes

| Mode | CLI flag | Behavior |
|---|---|---|
| **full** | *(default)* | Analyze all configured paths. Safe and complete. |
| **fast** | `--fast` | Analyze only staged files within configured paths. Ideal for pre-commit hooks. |
| **fast-branch** | `--fast-branch` | Analyze files that differ between the current branch and the main branch (staged + branch diff). Ideal for CI/CD and pre-push. |
| **fast-dirty** | `--fast-dirty` | Analyze the **unified working tree**: tracked files modified vs `HEAD` (staged or unstaged) ∪ non-ignored untracked files. Ideal for AI agentic hooks and mid-review checks before committing. |
| **files** | `--files` / `--files-from` | Analyze an **explicit list of files** supplied by the operator (or a manifest written to disk). Ideal for IDE on-save, shallow CIs, and external tools that already produced a path list. See [How-To: --files / --files-from](how-to/files-flag.md). |

```mermaid
graph TD
    A{Execution mode?}
    A -->|full| B[All configured paths]
    A -->|fast| C[Only staged files<br/>within configured paths]
    A -->|fast-branch| D[Staged files +<br/>branch diff vs main]
    A -->|fast-dirty| H[Working tree diff vs HEAD<br/>∪ untracked non-ignored]
    A -->|files| G[Explicit user-supplied list<br/>--files / --files-from<br/>filtered by --exclude-pattern]
    C -->|No staged files<br/>match paths| E[Job skipped]
    D -->|Cannot compute diff<br/>e.g. shallow clone| F{fast-branch-fallback?}
    F -->|full| B
    F -->|fast| C
    H -->|Clean working tree| E
    G -->|No supplied files<br/>match paths| E
```

## Which jobs are accelerable?

When running with `--fast`, `--fast-branch`, `--fast-dirty` or `--files` / `--files-from`, GitHooks replaces the `paths` of accelerable jobs with only the relevant files. Jobs with no matching files are **skipped entirely** (with reason `"no input files match its paths"` in files mode). Non-accelerable jobs (phpunit, phpcpd, composer-*, script) ignore the file list and run with their original `paths`.

| Type | Accelerable by default | Reason |
|---|---|---|
| phpstan, phpcs, phpcbf, phpmd, parallel-lint, psalm | **Yes** | Analyze individual source files |
| phpunit, phpcpd | **No** | phpunit runs tests (not source), phpcpd needs the full codebase for duplication detection |
| custom | **No** | Opt-in via `accelerable: true` in structured mode |

You can override the default for any job:

```php
// Disable acceleration for a specific phpstan job
'phpstan_full' => [
    'type'        => 'phpstan',
    'paths'       => ['src'],
    'accelerable' => false,  // always analyzes full paths, even with --fast
],

// Enable acceleration for a custom job
'eslint_src' => [
    'type'             => 'custom',
    'executable-path'  => 'npx eslint',
    'paths'            => ['resources/js'],
    'accelerable'      => true,  // opt-in for --fast path filtering
],
```

Deleted files (staged with `git rm`) are automatically excluded — no tool receives a path to a file that no longer exists.

## Where to set the mode

The mode can be set at multiple levels, from lowest to highest priority:

1. **Default** — `full`.
2. **Per-hook-ref config** — `execution` key in hook refs.
3. **Per-job config** — `execution` key in job definition.
4. **CLI flag** — `--fast`, `--fast-branch`, `--fast-dirty` applies to all jobs in the invocation.
5. **Files mode** (`--files` / `--files-from`) — CLI-only. The three fast flags (`--fast`, `--fast-branch`, `--fast-dirty`) and the two files flags (`--files`, `--files-from`) are **mutually exclusive** with each other: combining any two emits `--X and --Y are mutually exclusive` and exits 1. `--exclude-pattern` requires one of the files flags.

This allows mixing modes within the same flow:

```php
'jobs' => [
    // Always runs against all files, even during --fast pre-commit
    'phpstan_src' => [
        'type'      => 'phpstan',
        'paths'     => ['src'],
        'execution' => 'full',
    ],
    // Follows the invocation mode
    'phpcs_src' => [
        'type'     => 'phpcs',
        'paths'    => ['src'],
        'standard' => 'PSR12',
    ],
],
```

Hook refs can also specify a mode:

```php
'hooks' => [
    'pre-commit' => [
        ['flow' => 'qa', 'execution' => 'fast'],          // staged files only
    ],
    'pre-push' => [
        ['flow' => 'qa', 'execution' => 'fast-branch'],   // branch diff
    ],
],
```

!!! info
    For `pre-commit` events, fast mode is activated automatically. You don't need to specify `'execution' => 'fast'`.

## Fast-branch fallback

When `--fast-branch` cannot compute the diff (e.g. in a shallow clone or detached HEAD), the `fast-branch-fallback` option determines the behavior:

| Value | Behavior |
|---|---|
| `'full'` (default) | Falls back to analyzing all configured paths. |
| `'fast'` | Falls back to analyzing only staged files. |

```php
'flows' => [
    'options' => [
        'main-branch'          => 'main',   // auto-detected if omitted
        'fast-branch-fallback' => 'fast',   // fall back to staged files
    ],
],
```

## Fast-dirty mode (`--fast-dirty`)

`--fast-dirty` analyzes the **unified working tree** — everything dirty vs `HEAD`, regardless of stage state, plus untracked files that are not ignored. The set is the union of:

```bash
{ git diff --name-only --diff-filter=ACMR HEAD     # tracked: staged or unstaged, excluding deletions
  git ls-files --others --exclude-standard         # untracked, respecting .gitignore
} | sort -u
```

`--diff-filter=ACMR` keeps **A**dded, **C**opied, **M**odified and **R**enamed entries (renames surface as the destination) and drops **D**eleted ones, so every path handed to a linter exists on disk.

### When to use it

- **AI agentic hooks** (Claude Code, Cursor, Cline, Copilot agent…). The agent edits files without staging them and you want the **same** flow that runs at pre-commit — typically with `--format=json` to save tokens. Before `--fast-dirty` each hook needed a few lines of bash to compute the list and feed `--files`; now it's a single flag.
- **Mid-review without committing**. The typical `voy a revisar lo que llevo antes de commitear` iteration. Avoids `git add -A && --fast` (pollutes the stage) and hand-rolled `--files`.

### Clean working tree

If the working tree is clean, **all accelerable jobs are skipped** with `no changes to validate` and the run exits **0**. There is no fallback to `full` — that would surprise the post-commit "nothing to validate" semantics that motivate the mode. Non-accelerable jobs (phpunit, phpcpd, composer-*) still run with their original `paths`; declare `accelerable: true` or move them to a different flow if you want them out of the agentic loop.

### Untracked files

Untracked files **are included** as long as they are not in `.gitignore`. If a scratch directory under a covered path produces noise, either add it to `.gitignore` or use `--exclude-pattern`:

```bash
githooks flow qa --fast-dirty --exclude-pattern='tmp/**,**/Generated/**'
```

### Mutually exclusive flags

`--fast-dirty` cannot be combined with `--fast`, `--fast-branch`, `--files` or `--files-from`. Each of those defines a different "input file set" semantics; combining two would be ambiguous, so the command exits 1 with a precise message (e.g. `--fast-dirty and --fast are mutually exclusive`).

### Composition with per-entry admission rules and dependencies

`fast-dirty` composes with [per-entry admission rules](configuration/flows.md#per-entry-admission-rules-only-files-exclude-files) and with [job dependencies](configuration/flows.md#job-dependencies-needs) the same way `--fast` does — the admission gate sees the unified dirty set and the `needs` propagation contract applies identically.

## Files mode (`--files` / `--files-from`)

When the input is an **explicit user-supplied list of files** (IDE on-save, manifests piped from `git diff`, shallow CIs where `--fast-branch` cannot compute a diff), use the `--files` flags instead of relying on git state.

```bash
# Single file — typical IDE on-save call
githooks flow qa --files=src/Foo.php

# Several files at once
githooks flow qa --files=src/Foo.php,src/Bar.php

# Manifest produced by an external tool
git diff --name-only origin/main...HEAD > /tmp/changed.txt
githooks flow qa --files-from=/tmp/changed.txt

# Manifest minus tests and generated code
githooks flow qa --files-from=/tmp/changed.txt \
                 --exclude-pattern='tests/**,**/Generated/**'
```

- **`--files=a,b,c`** — CSV. Paths resolve against CWD; absolute paths accepted as-is. Directories expand recursively to `.php` / `.phtml`.
- **`--files-from=PATH`** — manifest with one path per line. Tolerates comments (`#`), blanks, CRLF and UTF-8 BOM. Use it to bypass shell `ARG_MAX` limits.
- **`--exclude-pattern=glob1,glob2`** — drop matching paths from the input list (post-expansion). Same glob syntax as hook config (`*`, `**`, `?`).

`--files` and `--files-from` are mutually exclusive. Combining files mode with `--fast`, `--fast-branch` or `--fast-dirty` is rejected with a precise error (`--files and --fast-dirty are mutually exclusive`) and exits 1. `conf:check` rejects `files` / `files-from` declared in `flow.options` or in a job — they are CLI-only by design (volatile per invocation).

When files mode is active, JSON v2 sets `executionMode: "files"` and adds a root `inputFiles` block plus a per-job `inputFiles` slice on accelerable jobs. See [How-To: --files / --files-from](how-to/files-flag.md) for the full reference and [Output formats: Input files block](how-to/output-formats.md#input-files-block) for the JSON shape.
