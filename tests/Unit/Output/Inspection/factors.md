# Factor tables — diagnostic JSON output (FEAT-20)

Components that add `--format=json` to the diagnostic commands (`conf:check`,
`status`, `system:info`). Each table is materialised as a `@dataProvider` in the
sibling test file.

## A. `ResolvesDiagnosticFormat::resolveDiagnosticFormat()`

INVARIANT: returns `'text'` or `'json'`; warns to stderr **iff** the input is a
non-empty, unsupported value.

| Factor | Equivalence classes | AVL |
|---|---|---|
| `--format` value | absent (`''`), `text`, `json`, invalid (`csv`) | the empty string vs a present value |

| `--format` | output | warning |
|---|---|---|
| `''` (absent) | `text` | no |
| `text` | `text` | no |
| `json` | `json` | no |
| `csv` (invalid) | `text` | yes |

PATHOGENIC CLASS: invalid value must NOT raise/exit — it falls back to `text`
(consistency with `flow`). Covered: `ResolvesDiagnosticFormatTest::formatCases`.

## B. `SystemInfo::status()`

INVARIANT: classification of `processes` against `cpus` is correct at the
boundary `processes == cpus`.

| Factor | Equivalence classes | AVL |
|---|---|---|
| configFound | false (`processes === null`), true | — |
| processes vs cpus | `>`, `== cpus`, `== 1`, `2..cpus` | `cpus`, `cpus + 1`, `1`, `2` |

| configFound | processes vs cpus | status | warning |
|---|---|---|---|
| false | — | `no-config` | null |
| true | `processes > cpus` | `warning` | message |
| true | `processes == 1` (1 ≤ cpus) | `tip` | null |
| true | `2 ≤ processes ≤ cpus` | `ok` | null |

PATHOGENIC CLASS: `processes == cpus` → `ok` (a `>`→`>=` mutant would mislabel it
`warning`). Covered: `SystemInfoTest::statusCases` (`processes == cpus` row).

## C. `conf:check` job status (assembled in `CheckConfigurationFileCommand::buildJobsPayload`)

INVARIANT: a job is `ok` only when it builds AND validates clean.

| validateJob | buildCommand | status | issues |
|---|---|---|---|
| `[]` | ok | `ok` | `[]` |
| `[w1, …]` | ok | `warning` | `[w1, …]` |
| — | throws | `error` | `[exception message]` |

PATHOGENIC CLASS: a job whose `buildCommand()` throws must be `error` (not `ok`)
and must NOT change the exit code (job issues never flip `valid`). Covered at the
command level: `CheckConfigurationFileJsonTest`.

## D. `StatusJsonFormatter` targets

INVARIANT: targets are emitted raw for every status; the text placeholders
(`—`, `(not in configuration)`) never leak into JSON.

| status | targets | json targets |
|---|---|---|
| synced/missing/orphan | `[a, b]` | `[a, b]` |
| synced | `[]` | `[]` |
| orphan | `[]` | `[]` (NOT `(not in configuration)`) |

Covered: `StatusJsonFormatterTest::rawTargetsCases`.
