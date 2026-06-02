# Commit Message Validation

The `commit-msg` type validates the **commit message subject** against a set of declarative rules and wires to Git's `commit-msg` hook. It replaces the hand-written `custom` shell scripts (grep/sed) that teams used before: it is declarative, multiplatform (Linux/macOS/Windows, no bash), validated by `conf:check`, and produces the same output contract as any other job.

- **Type:** `commit-msg`
- **Accelerable:** No (it validates a message, not source files)
- **Executable:** none — runs **inline** (in-process), so there is no shell to spawn

!!! tip "Start with the preset"
    For the 90% case — "Conventional Commits or nothing" — the `conventional-commits` preset is two lines of config. Reach for the granular `rules` only when your convention differs.

## Quick start (preset)

```php
return [
    'hooks' => [
        'commit-msg' => ['commit-format'],
    ],
    'jobs' => [
        'commit-format' => [
            'type'   => 'commit-msg',
            'preset' => 'conventional-commits',
        ],
    ],
];
```

Then install the hooks so Git runs the job on every commit:

```bash
githooks hook:install
```

Now a commit whose subject does not follow [Conventional Commits](https://www.conventionalcommits.org/) is rejected:

```
$ git commit -m "Add stuff."
✗ commit-msg: subject failed rule 'pattern'.
  Subject:   Add stuff.
  Reason:    Use Conventional Commits: tipo(scope?)!?: descripción.
  Example:   feat(api): add user endpoint
```

A valid one (`git commit -m "feat(api): add user endpoint"`) passes silently and the commit is created.

!!! warning "Upgrading from an earlier version"
    The `commit-msg` job needs the message-file path that Git passes to the hook. If you installed your hooks **before this feature existed**, the generated hook script discards that argument. **Run `githooks hook:install` again** after upgrading so the script forwards Git's arguments; until then a `commit-msg` job reports *"no message file available"*. Reinstalling is safe and idempotent.

## The `conventional-commits` preset

The preset expands to:

| Rule | Value |
|---|---|
| `min-length` | `10` |
| `max-length` | `100` |
| `pattern` | `feat\|fix\|test\|docs\|refactor\|chore\|ci\|build\|perf\|style\|revert` + optional `(scope)`, optional `!`, then `: description` |
| `forbid-trailing-period` | `true` |
| `subject-case` | `lowercase` |
| `forbid-empty` | `true` |
| `merge-allowed` | `true` |

It is the only preset in this version. To adjust it, override individual rules (see below) — you don't have to redeclare the whole set.

## Rules

Declare `rules` to define your own convention, with or without a preset. The rule set is closed:

| Rule | Type | Default | Meaning |
|---|---|---|---|
| `min-length` | int ≥ 1 | — | Minimum subject length (UTF-8 code points). |
| `max-length` | int ≥ 1 | — | Maximum subject length. |
| `pattern` | string | — | PCRE regular expression the subject must match. |
| `pattern-message` | string | generic | Custom message shown when `pattern` fails. Only meaningful with `pattern`. |
| `forbid-trailing-period` | bool | `false` | Reject a subject ending in `.`. |
| `subject-case` | string \| null | `null` | Capitalization rule (see below). |
| `forbid-empty` | bool | `true` | Reject an empty subject. |
| `merge-allowed` | bool | `true` | Skip validation for merge / squash / fixup commits. |

Rules are evaluated in a fixed order and the **first** failing rule is reported: `merge-skip → forbid-empty → min-length → max-length → pattern → forbid-trailing-period → subject-case`.

### `subject-case`

| Value | Meaning |
|---|---|
| `lowercase` | After an optional `tipo(scope): ` prefix, the description has no capital letters. `feat(api): add endpoint` ✓ · `feat(api): Add Endpoint` ✗ |
| `sentence` | The description starts with a capital letter. `Add endpoint` ✓ · `add endpoint` ✗ |
| `null` | Not validated. |

### Merge commits

With `merge-allowed: true` (default), subjects starting with `Merge ` (case-insensitive), `squash! ` or `fixup! ` skip validation entirely — the job is reported as skipped, the commit proceeds. Detection is by subject only; the commit graph is not inspected.

## Configuration forms

### Preset + override (recommended for tweaks)

Explicit rules override the preset **entry by entry**; untouched preset rules stay active.

```php
'commit-format' => [
    'type'   => 'commit-msg',
    'preset' => 'conventional-commits',
    'rules'  => [
        'max-length'   => 120,    // override the preset's 100
        'subject-case' => null,   // disable the preset's lowercase rule
    ],
],
```

### Rules only (custom convention)

When your convention is not Conventional Commits, drop the preset and use `pattern`. For example, a team format `tipo (equipo) ID-Tarea título`:

```php
'commit-format' => [
    'type'  => 'commit-msg',
    'rules' => [
        'pattern'         => '/^(feat|fix|docs|refactor|chore|test) \([\w-]+\) [A-Z]+-\d+ .+/',
        'pattern-message' => 'Format: tipo (team) ID-Task title. e.g. feat (backend) PROJ-42 add user endpoint',
        'min-length'      => 15,
        'merge-allowed'   => true,
    ],
],
```

## Accepted keywords

`commit-msg` only accepts `type`, `preset`, `rules`, `warn-after` and `fail-after`. The path-oriented and process-oriented common keywords (`paths`, `executable-path`, `other-arguments`, `accelerable`, `execution`, `executable-prefix`, `cores`, `memory`) do **not** apply to an inline, path-agnostic validator and are rejected by `conf:check`:

```
$ githooks conf:check
✗ Configuration errors:
  • Job 'commit-format': key 'paths' is not applicable to type 'commit-msg'.
```

## Manual invocation

The job is wired to the hook for normal use, but you can run it by hand to test your rules or from an IDE. It **only validates** — it never creates or rewrites a commit.

```bash
# Validate a literal message (handy for testing rules / CI)
githooks job commit-format --message="feat(api): add user endpoint"

# Validate a specific file
githooks job commit-format --message-file=/tmp/msg.txt

# Machine-readable result for CI / scripts
githooks job commit-format --message="feat: add x" --format=json
```

`--message` and `--message-file` are mutually exclusive. When neither is given (nor a hook argument), the job falls back to the `GITHOOKS_COMMIT_MSG_FILE` environment variable and then to `.git/COMMIT_EDITMSG`. Exit code `0` means valid, `1` means invalid.

!!! note "Keep `commit-msg` out of QA flows"
    Wire `commit-msg` directly to the hook (`'commit-msg' => ['commit-format']`), not inside a `qa` flow. A reader expects `flow qa` to validate code, not commit metadata — and outside the hook there is usually no message to validate.

## What is **not** validated

Only the subject (first line) is checked. The body and footer are ignored, so there are no body-length or `Signed-off-by` rules. Auto-fix is out of scope: the job never rewrites the message — it reports valid/invalid and Git decides whether to accept the commit. Messages must be UTF-8.

## See also

- [Configuration: Hooks](../configuration/hooks.md) — wiring jobs to git hook events.
- [Configuration: Jobs](../configuration/jobs.md) — common job structure.
- [`githooks job`](../cli/job.md) — manual invocation and `--message` / `--message-file`.
- [`githooks hook`](../cli/hook.md) — installing the hook scripts.
