# Glob syntax

The same glob syntax is used everywhere a pattern appears in GitHooks:

- [Hook conditions](configuration/hooks.md#conditional-execution): `only-on` / `exclude-on` (branch patterns) and `only-files` / `exclude-files` (file patterns) inside a hook entry.
- [Per-flow-entry admission](configuration/flows.md#per-entry-admission-rules-only-files-exclude-files): `only-files` / `exclude-files` next to a job inside `flows.<X>.jobs`.
- [Branch-driven execution mode](configuration/flows.md#branch-driven-execution-mode-on): the keys of the `flows.<X>.on` map (branch patterns).
- [`--exclude-pattern` CLI flag](how-to/files-flag.md#-exclude-pattern): filters paths in `--files` / `--files-from` runs.
- [Job `paths` filtering](configuration/jobs.md): not a glob (it is a directory prefix list), but the same conventions apply when you combine it with `--files`.

This page is the canonical reference for the syntax.

## Operators

| Pattern | Matches | Notes |
|---|---|---|
| `*` | Any characters **except `/`** (file patterns) | `src/*.php` matches `src/User.php` but not `src/Models/User.php`. |
| `*` | Any characters **including `/`** (branch patterns) | `release/*` matches `release/v2.0` and `release/hotfix/x`. Branch patterns are flat strings — `/` is just a character. |
| `**` | Zero or more directory levels (file patterns only) | `src/**/*.php` matches `src/User.php`, `src/Models/User.php`, `src/A/B/C/User.php`. |
| `?` | Exactly one character (not `/` in file patterns) | `file?.php` matches `file1.php` but not `file10.php`. |
| `[abc]` | One character from the set | `file[12].php` matches `file1.php` and `file2.php`. |
| `[!abc]` | One character **not** in the set | `file[!0-9].php` matches `fileA.php` but not `file1.php`. |
| `{a,b,c}` | Alternation | `**/*.{js,ts,vue}` matches `app/foo.js`, `app/bar.ts`, `app/baz.vue`. |

!!! note "File patterns vs branch patterns"
    In **file patterns**, `*` stops at `/` and `**` is needed to cross directory levels — same semantics as `.gitignore`. In **branch patterns**, branch names are flat strings (Git allows `/` as a separator but it has no special meaning to globs), so `*` matches across slashes and `**` has no extra meaning.

## Common patterns

```php
// All PHP files under src/ (any depth)
'src/**/*.php'

// Just the top level of src/
'src/*.php'

// Tests only
'tests/**'                       // shorthand for tests/**/*
'**/*Test.php'                   // by suffix anywhere
'tests/**/*Test.php'             // restrict to tests/

// Front-end change set
'**/*.{js,ts,jsx,tsx,vue}'
'resources/js/**'

// Config / lockfiles
'composer.{json,lock}'
'**/.env*'                        // .env, .env.local, .env.testing

// Generated code (exclude)
'**/Generated/**'
'**/*.generated.php'

// Branch patterns
'master'                          // exact match
'main'
'release/v*'                      // release/v1, release/v2.5
'feature/*'                       // a single feature segment
'*'                               // catch-all (recommended last in `on`)
```

## Composition and precedence

Inside the same hook or flow entry, `only-*` and `exclude-*` **AND-compose**:

```php
'only-files'    => ['src/**/*.php'],
'exclude-files' => ['src/Legacy/**'],
```

The hook entry runs when at least one file matches `only-files` **and** that same file is not excluded by `exclude-files`. The match is per-file, then the rule decides admission for the whole entry.

When the same kind of rule appears at multiple levels (hook-level + flow-entry-level), each level evaluates independently and both must admit the work:

```
hooks.<event>.<ref>.only-files     ← hook-level: does the ref run at all?
                                     │ if yes:
flows.<X>.jobs[].only-files          ← per-job: does this specific job run?
```

See [Composition with hook-level rules](configuration/flows.md#composition-with-hook-level-rules).

## Pattern matching order in `flows.<X>.on`

In a `flows.<X>.on => [branch_pattern => attrs]` map, the order of the keys is the priority order: **first declared, first matched**. There is no longest-match resolution — the user controls precedence by ordering the map.

```php
'on' => [
    'release/v*' => ['execution' => 'full'],          // matches release/v1, release/v2.5
    'release/*'  => ['execution' => 'fast-branch'],   // catches the rest of release/*
    '*'          => ['execution' => 'fast'],          // everything else
],
```

`conf:check` emits a warning when no catch-all `'*'` is declared, so non-matching branches don't silently fall through.

## Anti-patterns

- **`only-files: ['src/']`** — a directory prefix, not a glob. It won't match files inside; use `'src/**'` instead.
- **`only-files: ['*.php']`** — top-level only. If you meant "all PHP", use `'**/*.php'`.
- **`only-files: ['src/**/file.php']`** — `**` only crosses directories when followed by `/`. This works in many implementations but prefer `'src/**/*.php'` or `'**/file.php'` for clarity.
- **`only-files: []`** — meaningless and rejected by [`conf:check`](cli/conf-check.md). Use `null` to cancel an inherited rule from `.local.php`.
- **Regular-expression syntax** (`^src/.*\.php$`, `(?:foo|bar)`) — not supported; use the operators in the table above.
- **`on => ['master' => …, 'master' => …]`** — PHP collapses duplicate map keys silently; only the last entry survives. Use a single entry per pattern.

## Quick mental model

If you have used `.gitignore`, the file-pattern rules above are the same. The two notable differences:

1. Branch patterns don't treat `/` as special.
2. Alternation with `{a,b,c}` is supported (some `.gitignore` parsers don't).
