# Factor table — `MessageFileResolver::resolve()` (FEAT-16, 4.0 `ALFIL_COMMIT_MSG_FILE`)

```
COMPONENTE: MessageFileResolver::resolve(?explicitFile, ?inlineContent, ?envFile, rootPath)
INVARIANTE(S):
  I1. The returned path comes from the highest-priority PRESENT source:
      explicit > inline > env > fallback.
  I2. A present source is returned as-is, even when its file does not exist —
      the caller reports "cannot read message file" (REQ-006). Only the
      fallback is existence-checked.
  I3. null if and only if no source is present AND the fallback does not exist.
  I4. "Present" means: explicit/env → non-null and non-empty string;
      inline → non-null (an empty inline message IS content: it is
      materialised and the validator reports `forbid-empty`).
```

| Factor | Clases de equivalencia | Valores AVL |
|---|---|---|
| `explicitFile` (hook `$1`, `--message-file`, `--message` materialised by the CLI) | `null`, `''` (absent), path | `''` (absent boundary); path to a missing file (returned as-is) |
| `inlineContent` (`--message` when a caller passes the literal) | `null` (absent), string (present) | `''` (present: materialised, NOT absent) |
| `envFile` (`ALFIL_COMMIT_MSG_FILE`, read by `CommitMsgJob`) | `null` (unset), `''` (set but empty → absent), path | `''` (absent boundary); path to a missing file (returned as-is) |
| fallback `<root>/.git/COMMIT_EDITMSG` exists | `T`, `F` | — |

## Decision table

`P` = present, `A` = absent, `*` = don't care. The chain short-circuits, so every
row below a present source is unreachable for that source.

| explicit | inline | env | fallback | `resolve()` | Row |
|---|---|---|---|---|---|
| P | * | * | * | explicit path | 1 — explicit wins over all three |
| P (missing file) | A | P | T | explicit path (I2, not env, not fallback) | 2 |
| A (`null`) | P | P | T | temp file with the inline content | 3 |
| A (`''`) | P (`''`) | P | T | temp file, empty content (I4 boundary) | 4 |
| A | A | P | T | env path | 5 — env wins over fallback |
| A | A | P (missing file) | T | env path (I2, not fallback) | 6 |
| A | A | P | F | env path | 7 |
| A (`''`) | A | P (`''`) | T | fallback (both empties are absent) | 8 |
| A | A | P (`''`) | F | `null` (I3) | 9 |
| A | A | A | T | fallback | 10 |
| A | A | A | F | `null` (I3) | 11 |

## Clase patógena

Rows 5–7 (**env present, nothing above it**). The resolver handled them since
FEAT-16 and `env_wins_over_fallback` was green, but no production caller ever
passed `$envFile` (`CommitMsgJob` hard-coded `null`), so from the CLI the rows
were unreachable: a `commit-msg` job inside `flow` / `flows` always landed on
rows 10–11. A pure unit test of the resolver is blind to this wiring bug; the
tests that close it sit one level up (`CommitMsgJobTest` with `putenv`), at
system level (`flow` through the artisan harness) and against the compiled
binary (`CommitMsgEnvFileReleaseTest`).

## Cobertura

- `MessageFileResolverTest::resolve_follows_the_source_priority_table` — rows 1–11.
- `CommitMsgJobTest` (`putenv`) — rows 2, 5, 6, 8/9 as seen by the job: env file
  validated, env wins over fallback, missing env file → "cannot read", explicit
  wins over env, empty env → "no message file available".
- `CommitMsgJobCommandTest::flow_reads_the_message_file_from_the_environment` — row 5 through `flow`.
- `CommitMsgEnvFileReleaseTest` (`@group release`) — rows 5 and 1 against the `.phar`.
