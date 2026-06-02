# Changelog

All notable changes to this project are documented here.

## [3.5]

### Added

- **Native `commit-msg` job — declarative commit-message validation.** A new job type that validates the commit-message subject against a closed set of declarative rules (`min-length`, `max-length`, `pattern`, `pattern-message`, `forbid-trailing-period`, `subject-case`, `forbid-empty`, `merge-allowed`) plus a `conventional-commits` preset, wired to Git's `commit-msg` hook. It replaces the hand-written shell scripts teams used before: declarative, multiplatform (no bash — Linux/macOS/Windows), validated by `conf:check`, and producing the same output contract as any other job. The job runs **inline** (in-process, no shell spawned) — a new execution mode for native PHP validators — so it costs sub-millisecond instead of a process fork. It validates only; it never creates or rewrites a commit (Git decides based on the exit code). Use the preset for the common case (`'commit-msg' => ['commit-format']` + `['type' => 'commit-msg', 'preset' => 'conventional-commits']`) or `rules` with a custom `pattern` for any other convention. Manual invocation for testing/IDEs: `githooks job <name> --message="feat: x"` / `--message-file=PATH`. **Upgrade note:** repos with hooks installed before this release must run `githooks hook:install` again so the generated hook script forwards Git's arguments to the engine; until then a `commit-msg` job has no message file to read. See [Commit Message Validation](tools/commit-msg.md).

- **Runtime diagnostics block and absolute timestamps for CI post-mortems.** A shared CI runner that hangs for 40 minutes with most of them silent left no way to tell whether PHP was blocked, the runner ran out of memory, or the agent's output buffer froze — the log carried only relative durations and no snapshot of the machine. GitHooks now emits three pieces of pure observability (no behaviour change): (1) a **runtime diagnostics block** — githooks version, platform, CPU count and cgroup limit, available/total **system** memory, 1/5/15 load averages and an absolute ISO-8601 start timestamp — printed before the `Settings:` header and **flushed immediately** so it survives a later hang; (2) **absolute `startedAt`/`endedAt` timestamps** (millisecond precision) per job and per flow, alongside the existing relative `duration`, so a gap in a CI log pins to the exact job; (3) a **`runtime` node** in JSON v2 carrying the same snapshot plus the flow span. The text block is **auto-on in CI** (multiline) and opt-in locally via the new `--diag` flag (compact single line) on `flow`, `flows` and `job`; its channel follows the conditions-header rule (text → stdout, clean-stdout formats → stderr only with `--show-progress`). The JSON `runtime` node and per-job timestamps are part of the JSON v2 contract — always present, independent of `--diag`. Fields unavailable on a platform (cgroup limit and memory off Linux, load on Windows) are `null` under the explicit-null pattern, so the contract never breaks. See [Runtime diagnostics and absolute timestamps](how-to/output-formats.md#runtime-diagnostics-and-absolute-timestamps).

- **`--format=claude-code` — native AI agent stop-hook output.** A new output format that emits the [Claude Code](https://claude.com/claude-code) stop-hook protocol directly, available on `flow`, `flows` and `job`. On success it prints nothing and exits 0; on a QA failure it prints a single `{"decision":"block","reason":"## job\n<output>…"}` JSON line **and still exits 0** — the stop-hook protocol only honours the block JSON on a zero exit, so a non-zero code would make the agent surface stderr and confuse it with a native block. The `reason` aggregates the plain-text output of every failed job (ANSI stripped, JSON-escaped) under a Markdown `## <jobName>` heading. A genuine configuration error (bad config, undefined flow) still exits 1. This pairs with `--fast-dirty` (3.4) — the working-tree *input* mode designed for AI agents — to close the loop end to end and replaces the per-repo bash wrapper that integrations previously needed. The format is named after its consumer; other agents will get their own opt-in `--format=<agent>` value when their protocols stabilise. See [AI Agent Hooks (Claude Code)](how-to/ai-hooks.md).

## [3.4.1]

### Fixed

- **Unknown CLI options no longer silently shift the parser** in `flow`, `flows` and `job` (BUG-21). The three execution commands keep `ignoreValidationErrors()` on so that `job <name> -- <args>` keeps forwarding extra args to the underlying tool, but a typo such as `flow qa --foo=bar --config=/path/x.php` would previously make Symfony swallow `--foo` *and* silently drop `--config`, then fall back to `qa/githooks.php` — wrong config, wrong jobs, no error. A new `ValidatesUnknownOptionsBeforeDashDash` concern now inspects the input tokens before either command reads `--config`, rejects unknown long options and short shortcuts (cluster-aware), and emits a Symfony-style `The "--foo" option does not exist.` per offender; `flow` and `flows` additionally emit a custom error if `--` itself is present (neither command supports passthrough). The new behaviour caps any input-validation typo at exit 1 *before* the configuration file is resolved, so a typo can no longer accidentally execute the project-wide QA flow.

- **`script`-typed jobs now report their job key in OK/KO/SKIP logs instead of the executable path** (BUG-23). `ScriptJob::getDisplayName()` historically overrode the parent and returned `$this->executable`, so two parallel jobs of `type: script` sharing the same `executable-path` (e.g. two shards invoking the same `./run-tests` runner with different `other-arguments`) printed two identical `./run-tests - OK. Time: …` lines, making them indistinguishable in the dashboard, JSON v2 dry-run and stats. The override was undocumented and inconsistent with every other Job type (Phpstan, Phpunit, Phpcs, Phpmd, Psalm, ParallelLint, Rector, PhpCsFixer, Phpcpd, Paratest, Custom, all of which inherit `JobAbstract::getDisplayName()` returning `$this->name`). Removed. The JSON v2 envelope is unaffected — its `name`/`type` fields come from `getName()`/`getType()`, not `getDisplayName()`.

## [3.4]

### Added

- **`--fast-dirty` execution mode.** Fourth execution mode targeting the **unified working tree**: tracked files modified vs `HEAD` (staged or unstaged, excluding deletions) ∪ untracked files honouring `.gitignore`. Fills the gap between `--fast` (staged only) and `--fast-branch` (branch diff). Designed for **AI agentic hooks** (Claude Code, Cursor, Cline, Copilot agent…) — the agent touches files without staging and we want the same pre-commit flow with `--format=json`. Available as `--fast-dirty` on `flow`/`flows`/`job` and as `execution: 'fast-dirty'` in flow/job declarations. Mutually exclusive with `--fast`/`--fast-branch`/`--files`/`--files-from`. Clean working tree → accelerable jobs skipped, exit 0 (no fallback to `full`). See [Fast-dirty mode](execution-modes.md#fast-dirty-mode-fast-dirty).

- **Intra-flow dependencies with `needs: [<job>, ...]`.** A flow entry can declare other jobs in the same flow it depends on:
    ```php
    'qa' => [
        'jobs' => [
            'yarn_install',
            ['job' => 'eslint',   'needs' => ['yarn_install']],
            ['job' => 'prettier', 'needs' => ['yarn_install']],
            'phpstan_src',  // independent — parallel with yarn_install
        ],
    ],
    ```
    Jobs wait until all their `needs` complete successfully; skip propagation is visible end-to-end (`needs X failed`, `needs X was skipped`). The TTY dashboard gains a `⏸ jobName (waiting X, Y)` lane and JSON v2 emits `needs: [...]`. `conf:check` validates the DAG statically (cycles, missing references, duplicates, empty list). **Behaviour change**: `fail-fast` now lets jobs in `running` finish naturally instead of terminating them — it cancels pending work, not in-flight work. See [Job dependencies (`needs`)](configuration/flows.md#job-dependencies-needs).

- **Per-flow execution mode by branch with `on => [branch_pattern => attrs]`.** A flow picks its execution mode based on the current branch:
    ```php
    'ci' => [
        'on' => [
            'master' => ['execution' => 'full'],
            '*'      => ['execution' => 'fast-branch'],
        ],
        'jobs' => [/* ... */],
    ],
    ```
    The execution mode lives **inside the flow declaration**, so a single CI step (`script: vendor/bin/githooks flows ci`) covers both protected and feature branches without branch-aware conditionals in the CI definition or duplicated jobs. Pattern matching is **first declared wins**. Branch detection cascades from a new `--branch=X` flag on `githooks flow` through `$GITHOOKS_BRANCH`, CI env vars and `git rev-parse`. The `Settings:` header reports `mode = X (flows.<X>.on)` when the branch match wins. See [Branch-driven execution mode (`on`)](configuration/flows.md#branch-driven-execution-mode-on).

- **Declarative per-flow-entry admission with `only-files` / `exclude-files`.** Flow entries in `flows.<X>.jobs` now accept the existing string form **or** an object `{job, only-files?, exclude-files?}` that gates whether the job runs based on the change set, independently of the job's own `paths` filtering. The decision is binary (`skipped: true` with `skipReason` vs run) and applies to all job types. In `full` mode the rules are no-op. Replaces the `type: custom` + `git diff … grep -qE …; exit 0` workaround that surfaces as `passed` and breaks on POSIX-less runners. Same glob semantics as hook-level `only-files`. Combined with `on` (above), the flow declaration decides both **which mode** runs per branch and **which jobs** are admitted per change set — the CI pipeline stays a single `flows` invocation without branch-aware conditionals or per-job rules duplicated in the CI YAML, and the admission logic is exercised the same way locally and in CI (GitLab CI's `rules:` / `changes:` and GitHub Actions's `paths:` filters can be coarse and pipeline-dependent). See [Per-entry admission rules](configuration/flows.md#per-entry-admission-rules-only-files-exclude-files).

### Fixed

- **`executable-prefix`, `fast-branch-fallback` and `reports` now cascade per-key from `flows.options`** when a flow declares its own `options` block. When a flow declared its own `options` to override an unrelated key (e.g. `processes`), the three keys above were read block-level instead of per-key and silently dropped their global value. Now they inherit per-key like `fail-fast` / `processes` / `time-budget` / `memory-budget` / `allocator` / `stats` already did. See [Per-key cascade](configuration/options.md#per-key-cascade).

- **GitLab CI / GitHub Actions sections no longer leak raw tool JSON when `--format=codeclimate` or `--format=sarif`** (or `reports.codeclimate` / `reports.sarif` in config) is active. Structured formats reconfigure each tool to emit JSON so the file-based formatters can parse it; the side effect was that failing jobs printed the raw JSON blob as the visible body of their CI section. A new humanising display layer translates the per-tool JSON into a familiar `file  line N  message  [rule]` listing while the raw payload stays available unchanged for file-based reports and JSON v2 `output`. See [Human-readable KO body](how-to/ci-cd.md#human-readable-ko-body-under-formatcodeclimate-formatsarif).

- **`githooks job <name> --format=json` now reflects the job's declared `execution` in the envelope.** When a job declared `execution: fast` / `fast-branch` (or the new `fast-dirty`), the JSON v2 envelope still reported `executionMode: "full"` / `source: "default"` even though the file-set filtering already honoured the declared mode — CI dashboards and AI consumers read the wrong mode. The `executionMode` value and its `effectiveOptions` `source` line now reflect `jobs.<name>.execution`.

## [3.3.3]

### Fixed

- **Fast-branch / fast no longer fail with spurious "no files" errors when a job's tool config strips every input via its internal exclusion list.** Repro: a branch touches only files under one subtree (e.g. `src/foo/...`); the wrapper hands those files to a job whose `.neon` declares `excludePaths.analyse: [src/foo]` (PHPStan) or whose `--ignore` CSV covers them (PHPCS). The tool drops 100 % of the input and exits non-zero with `[ERROR] No files found to analyse.` (PHPStan, exit 1) or `ERROR: All specified files were excluded or did not match filtering rules.` (PHPCS, exit 16 on older versions and the PHPCSStandards fork). Before this fix the wrapper reported the job as failed, breaking MRs in projects that split coverage across complementary jobs. PHPStan and PHPCS now recognise these "empty after filtering" exits, reinterpret them as `skipped: true` with `skipReason` instead of `success: false`, and bypass threshold evaluation (the tool didn't do real work, so timing it would be meaningless). PHPMD already tolerates this case natively (`exit 0` when its `--exclude` empties the set); the other accelerable tools (parallel-lint, psalm, rector, php-cs-fixer) silently ignore non-matching inputs and do not need an override.

## [3.3.2] ⚠️ Do not use — broken release

**This release is functionally identical to 3.3.1.** The git tag `v3.3.2` was published against a master commit whose bundled `.phar` binaries (`builds/githooks`, `builds/php7.4/githooks`) had never been updated from the `rc-3.3.2` branch where CI compiled them. Since GitHooks runs as a standalone `.phar`, installing `wtyd/githooks:3.3.2` ships the v3.3.1 binary under the v3.3.2 tag name. The fixes listed below are present in the source code of the tag but **not** in the executed binary.

**Use v3.3.3** — same fixes, correctly bundled.

### Fixed

- Code Climate and SARIF reports requested via `flows.options.reports.codeclimate` / `reports.sarif` in config or via `--report-codeclimate=PATH` / `--report-sarif=PATH` CLI flags came out empty (`[]` / no findings) when the primary `--format` was anything other than `codeclimate` / `sarif`. The flag that asks each tool for JSON output only activated on `--format=codeclimate|sarif`, so every tool ran with its default human-text format and the report parsers (which all do `json_decode()` over stdout) found nothing to extract. Affects every tool with a JSON-dependent parser: PHPStan (`--error-format=json`), PHPCS (`--report=json`), PHPMD (positional `json` format), Psalm (`--output-format=json`) and parallel-lint (`--json`). Fixed: tool-level JSON output is now requested whenever a codeclimate or sarif payload will be produced, regardless of how it was requested.

### Improved

- JUnit `<failure>` payloads now pretty-print embedded JSON so GitLab/Jenkins viewers render each finding on its own indented block. PHPMD already emits `JSON_PRETTY_PRINT` natively; PHPStan/PHPCS/Psalm/parallel-lint emit compact one-liner JSON. When a pipeline triggers tool JSON output (typical GitLab setup pairs JUnit + Code Climate), the JUnit `<failure>` arrived as a single 1000+ char line. The formatter now detects a parseable JSON span inside `<failure>` and re-encodes it with indentation; non-JSON outputs (custom jobs, scripts) and JSON with prologue/epilogue (PHPStan's "Instructions for interpreting errors" stderr block) are preserved verbatim except for the JSON span itself. Idempotent for already-pretty payloads (semantics preserved; bytes may differ because `JSON_UNESCAPED_SLASHES` turns `\/` into `/`).

## [3.3.1]

### Fixed

- `--fast` / `--fast-branch` no longer leave non-accelerable jobs (`phpunit`, `paratest`, `phpcpd`, `script`, `custom`, `composer-*`) running their full suites when the effective input set is empty (no staged files / no diff vs base). The skip is now universal: any job — accelerable or not, with or without `paths` declared — is skipped with reason `no changes to validate` when the mode produced no input. Restores parity with the v2.x contract ("nothing changed = nothing to run").
- `cache:clear` now resolves the **effective** cache path for each job instead of relying on hard-coded defaults. Previously the command read a regex on the top-level `.neon` (PHPStan, ignoring `includes:` and placeholders) and used hard-coded literals for every other tool — `cache:clear` silently reported "not found" while the real cache lived elsewhere. After the fix:
    - PHPStan: `tmpDir:` is followed through `includes:` recursively (cycle-safe) and `%currentWorkingDirectory%` / `%rootDir%` are expanded.
    - Psalm: reads `cacheDirectory` from `psalm.xml`, resolved relative to the XML.
    - PHPCS: reads job arg `cache`, then `<arg name="cache" value="..."/>` from the ruleset.
    - PHPUnit: reads `cacheResultFile` and `cacheDirectory` (10+) from `phpunit.xml` / `phpunit.xml.dist`.
    - Rector: best-effort regex over `cacheDirectory(...)` in `rector.php` (literal, `__DIR__ . '/literal'`, `sys_get_temp_dir() . '/literal'`). Default fixed to `sys_get_temp_dir() . '/rector_cached_files'` (was incorrect `/tmp/rector`, also non-portable on Windows).
    - PHP-CS-Fixer: best-effort regex over `setCacheFile(...)` in `.php-cs-fixer.php`; respects job arg `cache-file` over the config (matching what php-cs-fixer itself does).
    - PHPMD: default fixed to `.phpmd.result.cache` (was incorrect `.phpmd.cache`).
- When Rector / PHP-CS-Fixer config uses a dynamic expression for the cache path (variable, helper, env), `cache:clear` falls back to the default and surfaces a warning explaining why and how to override. Last-resort meta-arg `cache-dir` on the Rector job lets users force a path; PHP-CS-Fixer relies on its existing `cache-file` arg (which the tool itself respects as `--cache-file`). See [`cache:clear`](cli/cache-clear.md).
- PHPUnit cache precedence: when `phpunit.xml` declared both `cacheResultFile` (legacy) and `cacheDirectory` (PHPUnit 10+), GitHooks picked the legacy one and ignored the modern attribute. PHPUnit itself does the opposite — `cacheDirectory` wins. Users migrating to PHPUnit 10 with both attributes for transitional reasons saw `cache:clear` deleting the wrong path. Now `cacheDirectory` takes precedence, with `cacheResultFile` as fallback.
- PHPStan `tmpDir:` inside `services:` was misread as the root `parameters.tmpDir`. A NEON service constructor argument named `tmpDir` would be returned as the PHPStan tmpDir, leading `cache:clear` to delete a path the user never declared as cache. The resolver now requires `tmpDir:` to live directly under a top-level `parameters:` block.
- Meta-args with whitespace-only values (`'cache-dir' => '   '`, `'cache-file' => '   '`, `'cache' => '   '`) were accepted as literal paths. Now trimmed before validation; whitespace-only falls back to the default rather than producing `rm -rf "   "`.

## [3.3.0]

### New Features

#### Combined flow runs (`flows` command + meta-flows)

A new `flows` command runs **several flows in a single plan** — one PHP runtime, one shared thread budget, one combined `FlowResult` — replacing the typical "two CI steps that each spin up `composer install`" pattern with a single invocation.

- **Four invocation modes** (auto-detected from the args):
  - `flows qa` (single normal flow) — equivalent to `flow qa`, identical `FlowResult`.
  - `flows qa lint` (≥ 2 normal flows) — ad-hoc combination, jobs deduped by first-occurrence order.
  - `flows ci-pack` (1 meta-flow declared in config) — declarative composition with the meta-flow's own options.
  - `flows ci-pack deploy` (mixed) — meta-flow + extra flows, where per-flow / per-alias options are deliberately ignored.
- **Meta-flows in config** ([`flows.<alias>.flows`](configuration/flows.md#meta-flows)): a flow that lists other flows instead of jobs. Declares its own `options` and `reports`. `conf:check` validates the new shape: each `flows.<X>` declares exactly one of `jobs` or `flows`, references must point at existing normal flows (no nesting in v3.3), and the jobs/flows/meta-flows namespace must stay flat.
- **`flows.options` cascade per key**: `cli > flows.<X>.options > flows.options > default` (single-flow / declarative) collapses to `cli > flows.options > default` for ad-hoc and mixed runs (per-flow/alias options are intentionally ignored — see [why](https://wtyd.github.io/githooks/configuration/flows/#meta-flows)).
- **Ignored-options warning**: when a flow or alias's `options` block is ignored because of the run mode, `flows` emits a one-line notice naming the ignored sources so the operator knows what is and isn't being applied.

#### Cross-cutting conditions header + `effectiveOptions`

A new conditions header is emitted at the start of every `flow`, `flows` and `job` run to make the active options visible at a glance:

```
Settings:
  processes     = 4    (cli)
  fail-fast     = true (flows.ci-pack.options)
  mode          = full (default)
  time-budget   = none (default)
  memory-budget = none (default)
  allocator     = fifo (default)
  stats         = false (default)
Flows: qa, lint
```

Every row carries its `(source)` parenthesis — `(default)` included — so the column stays aligned and the audit trail is complete.

- **Channels**: stdout in text mode (default); stderr when a structured format is combined with `--show-progress`. Silent for plain `--format=json|junit|sarif|codeclimate` so stdout payloads stay clean.
- **JSON v2 contract** (always present in `flow` / `flows` / `job` runs): a new root `effectiveOptions` block listing each option's `value` and `source`. `source` ∈ `{cli, flows.<X>.options, flows.<alias>.options, flows.options, default}`.
- **`flows[]` root field** (multi-flow only): the list of normal flows actually executed after meta-flow expansion. Absent in `flow X` and single-flow degenerate runs (so existing single-flow consumers ignore it).

Both fields are additive. Consumers that read v2 today keep working unchanged; modern consumers (CI dashboards, AI tools) can now show the precise option resolution without cross-referencing config + CLI.

#### Files mode (`--files` / `--files-from` / `--exclude-pattern`)

`flow` and `job` accept three new flags that drive a flow against an **explicit list of files** supplied by the user. Covers IDE on-save (single-file analysis), CIs with shallow checkouts where `--fast-branch` cannot compute a diff, and any external tool that already produced a list of paths.

- **`--files=a,b,c`** — CSV. Paths resolve against CWD; absolute paths are accepted as-is. Directories expand recursively to `.php` / `.phtml`.
- **`--files-from=PATH`** — manifest with one path per line. Comments (`#`), blanks, CRLF and UTF-8 BOM are tolerated. Use this to bypass the shell `ARG_MAX` limit (`git diff --name-only origin/main...HEAD > /tmp/changed.txt && githooks flow qa --files-from=/tmp/changed.txt`).
- **`--exclude-pattern=glob1,glob2`** — drop matching paths from the input list (post-expansion). Same glob syntax as hook config (`*`, `**`, `?`). Requires `--files` / `--files-from`.
- **Behaviour**: accelerable jobs (phpstan, phpcs, phpcbf, phpmd, psalm, parallel-lint, php-cs-fixer, rector, custom with `accelerable: true`) run only on the intersection of input files and their configured `paths`; jobs with no match are skipped with reason `"no input files match its paths"`. Non-accelerable jobs (phpunit, phpcpd, composer-*, script) ignore the list and run with their original `paths`.
- **JSON v2**: when files mode is active, `executionMode` is `"files"` and a new root `inputFiles` block plus per-job `inputFiles` slice (accelerable jobs only) are emitted. Backward-compatible — fields are absent in the legacy modes.
- **Mixing**: `--files` / `--files-from` win over `--fast` / `--fast-branch` with a warning. The two file flags are mutually exclusive.
- **CLI-only**: `conf:check` rejects `files` / `files-from` keys declared in `flow.options` or in a job (volatile by design). See [How-To: --files / --files-from](how-to/files-flag.md).

#### Multi-report ([`reports`](configuration/options.md#multi-report) / `--report-*`)

PHPUnit-style multi-report: a single `flow` (or `job`) run can emit several report files at once instead of being executed once per format. Pipelines that need SARIF (Code Scanning) plus JUnit (test dashboards) plus Code Climate (GitLab MR widgets) no longer have to re-analyse everything 3 times.

- **CLI flags**: `--report-json=PATH`, `--report-junit=PATH`, `--report-sarif=PATH`, `--report-codeclimate=PATH`. Each one writes the corresponding format to the given path. Combine as needed.
- **Declarative config**: new `reports` map under `flows.options` and per-flow `options`:
  ```php
  'options' => ['reports' => ['sarif' => 'reports/qa.sarif', 'junit' => 'reports/junit.xml']]
  ```
- **Precedence**: CLI overrides config **format by format**. `--report-sarif=other.sarif` only overrides the SARIF entry; other formats keep config values.
- **`--no-reports`**: PHPUnit `--no-coverage`-style flag that skips the config `reports` section without cancelling CLI `--report-*` flags. Lets a consumer (an AI tool, an ad-hoc script) read clean JSON from stdout without dropping side-effect files declared by the project's config:
  ```bash
  githooks flow qa --format=json --no-reports
  ```
- **`--format` is unchanged**: still governs **stdout** the same way as in 3.2. `--format=sarif --report-sarif=foo.sarif` is legal and produces both stdout SARIF and the file.
- **`conf:check` validation**: rejects unsupported format keys, non-string paths and unwritable target locations; warns when the parent directory does not exist (it gets created on run).

#### Performance monitor — flow `time-budget` + per-job `warn-after` / `fail-after`

Two parallel, independent systems that watch the temporal health of every QA run:

- **Per-job thresholds** (`jobs.<name>.warn-after` / `fail-after`, seconds): catch local regressions of a specific job. Crossing `warn-after` annotates `⚠`; crossing `fail-after` flips a passing job to KO with exit `1`.
- **Flow time-budget** (`flows.options.time-budget` or `flows.<name>.options.time-budget`): catch accumulated drift across the whole flow. The post-hoc sum of executed-job durations is compared with `warn-after` / `fail-after` declared at the flow level. **A flow that crosses `fail-after` exits 1 even when every job passed individually** — the conceptual key of the feature.
- **Independence**: declaring `time-budget` at the flow level does NOT propagate `warn-after` / `fail-after` to individual jobs. The two layers answer different questions ("is this job regressing?" vs. "is the pipeline as a whole regressing?") and remain decoupled.
- **CLI overrides**: `--warn-after=N`, `--fail-after=N` (flow-level on `flow` / `flows`; job-level on `job`). `--no-time-budget` disables both layers for that run; mixing it with `--warn-after` emits a warning on stderr.
- **JSON v2 (explicit-null pattern)**: a new root `timeBudget` field (object or `null`) and per-job `threshold` field (object or `null`) are always present. Consumers can write `if (job.threshold) { … }` without existence checks. `reason` is a string when warned/failed is `true`, `null` otherwise.
- **Conditions header**: extended with a `time-budget=...` segment showing the effective values and their origin (`flows.options`, `flows.<X>.options`, `cli`, `default`).
- **`conf:check` validation**: rejects non-positive integers, `warn-after >= fail-after`, `time-budget` placed inside a job; warns on unknown keys with did-you-mean suggestions.

#### Memory budget + 2D allocator + RSS sampler (Linux)

GitHooks now declaratively watches RSS consumption per job and across the whole flow, schedules admissions in 2D (cores + memory) when both axes are constrained, and surfaces peaks in a canonical `--stats` table — none of GrumPHP, CaptainHook, lefthook, pre-commit or golangci-lint expose this combination.

- **Per-job memory threshold** (`jobs.<name>.memory`): two equivalent forms.
  - Short form `memory: 2000` (MB) — single warn threshold AND scheduler reservation when a flow `memory-budget` is declared.
  - Extended form `memory: { warn-above: 1500, fail-above: 2000 }` — explicit thresholds, no reservation.
  - Crossing `warn-above` annotates `⚠`; crossing `fail-above` flips the job to KO with exit `1` even when the tool itself returned `0`.
- **Flow `memory-budget`** (`flows.options.memory-budget` or per-flow): observational watchdog over the simultaneous RSS sum across jobs in flight. Crossing `fail-above` **kills jobs in flight** (`process->stop(0)`) and skips the queued ones with reason `"flow memory-budget exceeded"`. The flow exits 1 even if every individual job had passed (the conceptual key of the feature).
- **2D allocator** (`flows.options.allocator: fifo|greedy`): when a `memory-budget` is declared **and** at least one job has a short-form `memory:` reservation, the pool admits jobs only when both cores and memory fit. FIFO blocks the entire queue when the head does not fit; greedy scans for the first fitting job. 1D mode (cores only) is preserved when either side of the precondition is missing.
- **RSS sampler**: Linux via `/proc/<PID>/status` walked across the process tree (root + descendants — Symfony's shell wrapper alone is ~1 MB; the actual analyzers are children); macOS via a single `ps -o pid=,ppid=,rss= -ax` invocation per tick. Polled every 1 second while jobs are in flight. Windows degrades gracefully — a short stderr warning (`⚠ Memory budget disabled: RSS sampling not available on Windows`) disables thresholds; the 2D allocator still schedules from declared `memory:` reservations and `--stats` still emits the cores axis.
- **`--stats` table**: 5-column summary (Job / Status / Time / Peak Cores / Peak Memory) with a TOTAL row + temporal attribution lines `Memory peak at Xs: jobA Pmb + jobB Pmb...` and `Cores peak at Xs:  jobA + jobB...`. Active when `--stats` (CLI) or `stats: true` (config).
- **CLI overrides**: `--memory-warn-above=N`, `--memory-fail-above=N`, `--no-memory-budget`, `--allocator=fifo|greedy`, `--stats`. Apply flow-level except in `githooks job` where they apply to the single job.
- **JSON v2**: new root-level `memoryBudget` and `stats` blocks (always present under the explicit-null pattern), per-job `memoryReserved`, `memoryPeak`, `memoryThreshold` and `killedReason`. SARIF / JUnit / Code Climate are unchanged in this iteration.
- **Conditions header**: extended with `memory-budget=warn-above=WMB,fail-above=FMB (origin)`, `allocator=fifo|greedy (origin)` and `stats=true|false (origin)` segments alongside `time-budget`.
- **`conf:check` validation**: positive-integer guards, warn/fail ordering, `memory > memory-budget.warn-above` (could-never-run), unknown allocator values, `memory-budget` typo suggestions.

#### `cores` ↔ native thread flag interchangeability

`cores: N` and the tool's native threading flag (`parallel` on phpcs/phpcbf, `threads` on psalm, `jobs` on parallel-lint, `processes` on paratest) now work identically in both directions: declaring **either** one reserves N cores in the budget and emits the right CLI flag at runtime. Until v3.3 declaring only the native flag (without `cores`) was silently dropped in parallel mode and the allocator distributed the budget evenly instead.

- **No config change required**: the existing pattern of pinning with `cores: N` keeps working unchanged.
- **`conf:check` warning — single-threaded tools**: declaring `cores > 1` on `phpmd`, `phpunit` or `phpcpd` now emits a warning ("`<tool>` is single-threaded; `cores` reserves slots in the budget without benefit"). The tool only uses one core, so reserving more slows admission of other jobs without gain. `cores: 1` and absence of `cores` are silent. `type: custom` is exempt — user scripts may have their own concurrency the system can't inspect.
- **`conf:check` fix — phpcbf**: the conflict warning between `cores` and `parallel` (already emitted for phpcs) now applies to phpcbf as well.
- **`conf:check` validation — native flag**: when declared without `cores`, the native threading flag (`parallel` / `threads` / `jobs` / `processes`) is validated as a positive integer — symmetric with `cores`. A `parallel: -1` or `threads: '4'` now warns instead of silently degrading at the allocator.
- **Symmetric clamp**: a native flag value > `processes` is clamped to the budget at runtime, the same way `cores: N > processes` was clamped before.
- **The flow rules — args clamp at every path**: until v3.3 a job declaring more cores than the flow's `processes` budget still spawned its declared workers in the SO (the pool's accounting was clamped, but `args['parallel']` / `args['threads']` / etc. were not). Same job in two flows ("local" with `processes: 4`, "ci" with `processes: 16`) had to choose one of the two budgets in its declaration. Now `applyThreadLimit()` clamps the override to the flow's budget before reaching the tool, in both the explicit-override and the sequential-default paths. Declare the maximum your job can use; each flow caps it.
- **`conf:check` cross-flow warning for uncontrollable jobs**: phpstan reads its workers from `.neon` and custom jobs are opaque scripts — GitHooks cannot force either to honour the flow budget at runtime. When `phpstan.maximumNumberOfProcesses` (read from the configured `.neon`) or `cores: N` declared on a `type: custom` job exceeds a flow's `processes`, `conf:check` now emits a warning per affected flow naming both values and explaining that other jobs will wait in serial while the offending one runs. Same job referenced by multiple flows is validated against each flow's budget independently; flows that fit are silent. Warning, not error — the user may know their machine can absorb it.

### Deprecations

#### kebab-case keys for `jobs.<name>` (step 1 of 3)

The four legacy camelCase keys inherited from v2 inside `jobs.<name>` are deprecated in favour of their kebab-case counterparts. Both forms keep working in v3.3.x; the camelCase forms will be **removed in v4.0**.

| camelCase (deprecated) | kebab-case (canonical)  |
| ---------------------- | ----------------------- |
| `executablePath`       | `executable-path`       |
| `otherArguments`       | `other-arguments`       |
| `ignoreErrorsOnExit`   | `ignore-errors-on-exit` |
| `failFast`             | `fail-fast`             |

- **Runtime warning**: every command that loads the config (`flow`, `flows`, `job`, `conf:check`, `system:info`) emits a `Deprecated: 'X' is renamed to 'Y'. Will be removed in v4.0.` line on stderr per camelCase key found.
- **Structured output**: a new root-level `deprecations[]` block in JSON v2 (and `runs[0].properties.deprecations` in SARIF) lists each detection as `{job, oldKey, newKey, removalVersion, kind}`. As a side-effect, the JSON v2 also gains a root `warnings[]` field (always present, empty when no warnings) — useful for CI dashboards and AI consumers.
- **Conflict**: declaring both forms for the same key in the same job aborts that job with an error (`conflicting keys '...' and '...'`). Pick one.
- **Out of scope for v3.3**: `conf:migrate` is **not** updated yet — that is step 2 of the deprecation plan, in a later v3.x. The camelCase removal itself is step 3, in v4.0.

Migration guide: [Migration → v3.3 deprecations](migration/v33-deprecations.md).

---

## [3.2.0]

### New Features

#### Redesigned output system

The output behaviour now depends on the format and the execution context. The unifying rule: **the format decides whether the output streams live or is buffered and emitted at the end.**

- **Live streaming in `githooks job X` (single job)**: tool output (phpstan, phpcs, etc.) is now streamed in real time instead of buffered. Long-running jobs (phpmd, phpunit with coverage) no longer look frozen — you see the tool's actual progress as it happens.
- **Live streaming in `githooks flow` with `processes=1`**: each job is streamed with a header separator between jobs (like `make` or `docker compose up`). You see each tool's output as it runs instead of only `OK/KO` lines at the end.
- **Interactive parallel dashboard in `githooks flow` with `processes > 1`**: when running in a TTY, the output upgrades to a live dashboard with three states — ⏺ queued, ⏳ running (with a live timer), ✓/✗ done. On completion it collapses to a clean summary. In non-TTY environments (CI, piped stdout) it falls back to append-only streaming text so logs remain parseable. Activated automatically via `posix_isatty(STDOUT)`; no flag needed.
- **stdout/stderr split for structured formats**: for `json`, `junit`, `codeclimate` and `sarif`, progress lines (`OK job (Xms) [Y/Z]`, `Done.`, colours) route to stderr and the structured payload stays on stdout. Enables `githooks flow qa --format=json > report.json` without contamination.
- **TTY-aware progress with `--show-progress` override**: the stderr progress handler only emits when stderr is a TTY, so `flow|job --format=json | jq ...` works off pipes, CI and agents **without `2>/dev/null`**. Pass `--show-progress` to force progress even off a TTY — useful for long-running CI pipelines. `--dry-run` never emits progress.

#### Output formats

- **JSON schema v2 ([`--format=json`](how-to/output-formats.md#json-v2))**: enriched per-job fields (`type`, `exitCode`, `paths`, `skipped`, `skipReason`, `fixApplied`) plus top-level `version: 2`, `executionMode`, `passed`, `failed`, `skipped` counters. Stable contract for AI tools, CI dashboards and scripts.
- **JUnit `<skipped>` support**: skipped jobs now emit `<skipped>` elements with a reason attribute.
- **Code Climate format ([`--format=codeclimate`](how-to/output-formats.md#code-climate))**: GitLab-compatible Code Quality report.
- **SARIF format ([`--format=sarif`](how-to/output-formats.md#sarif))**: SARIF 2.1.0 report for GitHub Code Scanning, Azure DevOps and other static-analysis consumers.
- **Unified output target for structured formats**: `json`, `junit`, `codeclimate` and `sarif` all print to stdout by default; pass `--output=PATH` to write the payload to a file. Shell redirection (`> file`) remains equivalent.

#### CI integration

- **Native CI annotations ([CI/CD Integration](how-to/ci-cd.md#ci-annotations))**: auto-detects `GITHUB_ACTIONS=true` or `GITLAB_CI` and wraps job output in `::group::`/`::endgroup::` plus `::error file=…,line=…::` annotations (GitHub) or `section_start:`/`section_end:` markers (GitLab). Parses `file.php:LINE` patterns from tool output.
- **`--no-ci` flag**: opt out of the auto-detection when a CI env var is set but you want plain output (running `act` locally, custom CI where those markers aren't parsed, or scripting on top of GitHooks).

#### New native job types
- **[PHP CS Fixer (`type: php-cs-fixer`)](tools/phpcsfixer.md)**: native support with `config`, `rules`, `dry-run`, `diff`, `allow-risky`, `using-cache`, `cache-file` keywords. Accelerable.
- **[Rector (`type: rector`)](tools/rector.md)**: native support with `config`, `dry-run`, `clear-cache`, `no-progress-bar` keywords. Accelerable.
- **[Paratest (`type: paratest`)](tools/paratest.md)**: first-class support for [paratest](https://github.com/paratestphp/paratest), the parallel driver for PHPUnit. Inherits every PHPUnit keyword and adds `processes` (linked to `cores`).

#### Thread budget

- **Per-job `cores` reservation ([`cores`](configuration/jobs.md#reserving-cores-cores-or-the-tools-native-flag))**: every job can declare `cores: N` to reserve N slots in the thread budget. Controllable tools (phpcs, psalm, parallel-lint, paratest) automatically receive their native threading flag (`--parallel`, `--threads`, `-j`, `--processes`) with the same value, so you configure parallelism once per job regardless of the tool. Budget-only tools (phpstan, custom jobs) use `cores` to keep the `--monitor` peak accurate without forcing worker count. `conf:check` warns when `cores` coexists with a tool's native threading flag.

#### Other
- **`conf:check` command truncation**: long generated commands are truncated to 80 chars (with `…`) in the job table to keep the output readable on narrow terminals. `githooks job X --dry-run` still shows the full command.
- **All supported tools ship as dev dependencies**: `brianium/paratest`, `friendsofphp/php-cs-fixer`, `rector/rector` and `sebastian/phpcpd` are now declared in `require-dev`, and `psalm` is correctly stripped from the `.phar` at build time (it was being embedded by mistake). Running `composer install` in the repo gives every supported tool a binary under `vendor/bin/`, and the distributed `.phar` no longer ships QA tools internally.

---

## [3.1.0]

### New Features
- **Local override (`githooks.local.php`)**: GitHooks looks for a `githooks.local.php` file alongside `githooks.php`. If found, its contents are merged over the main config using `array_replace_recursive`. Allows per-developer environment customization without modifying the shared config. Add `githooks.local.php` to `.gitignore`. See [Docker & Local Override](how-to/docker-local-override.md).
- **`executable-prefix` option**: New option at global, flow, and job level. Prepends a command to all job executables (e.g. `'docker exec -i app'`). Per-job override with `''` or `null` to opt out. Enables Docker, Laravel Sail, and remote environments from a single config. See [Options: executable-prefix](configuration/options.md#executable-prefix).
- **Extra arguments via `--` for `job` command**: `githooks job phpunit_all -- --filter=testFoo` passes extra flags to the underlying tool. Enables dynamic execution from AI tools, scripts, or quick debugging without modifying configuration. See [`githooks job`](cli/job.md).
- **External documentation site**: Full MkDocs Material site with getting started guide, configuration reference, CLI reference, tool docs, how-to guides, migration guides, and comparison page.

### Bug Fixes
- Fix skipped job warnings not showing orange color in terminal output.
- Fix parallel execution deadlock when a job's reserved cores exceeded the total `processes` budget. The thread allocator now clamps both explicit `cores: N` overrides and uncontrollable tools' default workers (e.g. PHPStan reading 4 from `.neon` while `processes: 2`) to the budget, so the admission queue can always admit the head instead of rejecting it forever and spinning the executor at 100% CPU.

---

## [3.0.0] - 2026-04-10

### Breaking Changes
- **PHP minimum raised to 7.4**. Dropped support for PHP 7.0-7.3.
- **SecurityChecker tool removed**. Use a [`custom` job with `composer audit`](tools/custom.md) as replacement.
- **New configuration format: hooks/flows/jobs**. Replaces the previous `Options`/`Tools` format. The old format still works but emits a deprecation warning.
- **`tool` command deprecated**. Replaced by [`flow`](cli/flow.md) and [`job`](cli/job.md) commands. Will be removed in v4.0.
- **YAML configuration deprecated**. PHP format is now the primary format. YAML still works but emits a deprecation warning. Will be removed in v4.0.

### New Architecture — Hooks, Flows, Jobs
- **[Hooks](configuration/hooks.md)**: Map git events (`pre-commit`, `pre-push`, etc.) to flows and jobs. Uses `core.hooksPath` with a universal script instead of copying files to `.git/hooks/`.
- **[Flows](configuration/flows.md)**: Named groups of jobs with shared options (`fail-fast`, `processes`). Reusable across hooks and directly executable from CLI/CI.
- **[Jobs](configuration/jobs.md)**: Individual QA tasks with declarative configuration. Each job declares a `type` (phpstan, phpcs, phpunit, custom, etc.) and its arguments.

### New Commands
- [`githooks flow <name>`](cli/flow.md) — Run a flow by name. Supports `--fail-fast`, `--processes=N`, `--exclude-jobs`, `--only-jobs`, `--dry-run`, `--format=json|junit`, `--fast`, `--fast-branch`, `--monitor`.
- [`githooks job <name>`](cli/job.md) — Run a single job by name. Supports `--dry-run`, `--format=json|junit`, `--fast`, `--fast-branch`.
- [`githooks hook:run <event>`](cli/hook.md) — Run all flows/jobs associated with a git hook event (called by the universal hook script).
- [`githooks status`](cli/status.md) — Show installed hooks, their sync state with config (synced/missing/orphan), and target flows/jobs.
- [`githooks system:info`](cli/system-info.md) — Show detected CPUs and current `processes` configuration with budget warning.
- [`githooks conf:migrate`](cli/conf-migrate.md) — Migrate v2 configuration to v3 format with automatic backup.
- [`githooks cache:clear`](cli/cache-clear.md) — Clear cache files generated by QA tools. Accepts job names, flow names, or a mix.

### Updated Commands
- [`githooks hook`](cli/hook.md) — Now uses `core.hooksPath` + `.githooks/` directory instead of copying scripts to `.git/hooks/`. `--legacy` flag preserves old behavior (Git < 2.9).
- `githooks hook:clean` — Default now removes `.githooks/` + unsets `core.hooksPath`. `--legacy` flag removes individual hooks from `.git/hooks/`.
- [`githooks conf:init`](cli/conf-init.md) — Now supports `--legacy` flag to generate v2 format.
- [`githooks conf:check`](cli/conf-check.md) — Updated for v3: shows Options, Hooks, Flows, and Jobs tables with the full command each job will execute. Deep validation: verifies executables exist, paths are valid, and config files are accessible.

### New Job Types
- **[Custom](tools/custom.md)**: Replaces the v2 `script` tool. Supports `script` key (simple mode) and a new structured mode via `executablePath` + `paths` + `otherArguments`. Structured mode enables `--fast` acceleration identical to standard tools.

### Execution Modes and Structured Output
- **[`--format=json` and `--format=junit`](how-to/output-formats.md)**: Structured output for `flow` and `job` commands. JSON for machine-readable results; JUnit XML for CI test reporting.
- **[`fast-branch` execution mode](execution-modes.md)**: New third mode alongside `full` and `fast`. Analyzes files that differ between the current branch and the main branch. Ideal for CI/CD. Non-accelerable jobs always run with full paths. Per-job `accelerable` key overrides the default. Deleted files are excluded automatically.
- **`fast-branch-fallback` option**: Controls behavior when `fast-branch` cannot compute the diff (e.g. shallow clone). Values: `full` (default) or `fast`.
- **`main-branch` option**: Configure the main branch name for `fast-branch` diff computation. Auto-detected if not specified.
- **[Thread budget](how-to/parallel-execution.md)**: `processes` now controls total CPU cores, not just parallel jobs. GitHooks distributes threads across jobs respecting each tool's capabilities (phpcs `--parallel`, parallel-lint `-j`, psalm `--threads`). PHPStan workers detected from `.neon` config.
- **`--monitor` flag**: Shows peak estimated thread usage after flow execution, with warning if budget was exceeded.
- **Job argument validation**: `conf:check` and `flow`/`job` commands validate job configuration keys and types at parse time.

### Developer Experience
- **`--dry-run` flag**: Shows the exact shell command each job would execute without running anything. Works with all output formats — `--format=json` includes a `command` field per job.
- **`--only-jobs` flag**: Inverse of `--exclude-jobs` for the `flow` command. Run only the specified jobs: `githooks flow qa --only-jobs=phpstan_src,phpmd_src`.
- **Deep validation in `conf:check`**: Checks that executables exist, that configured `paths` are real directories, and that referenced config files are accessible.
- **Auto-detection of `executablePath`**: When omitted, GitHooks looks for `vendor/bin/{tool}` before falling back to system PATH.

### Conditional Execution
- **[`exclude-files`](configuration/hooks.md#condition-keys)**: Excludes staged files matching glob patterns from triggering execution. Always prevails over `only-files`.
- **[`exclude-on`](configuration/hooks.md#condition-keys)**: Excludes branches matching glob patterns. Always prevails over `only-on`.
- **Double-star (`**`) glob support**: File patterns now support `**` for recursive directory matching. `src/**/*.php` matches all PHP files under `src/` at any depth.
- **`hooks.command` config key**: Customize the command used in generated hook scripts (e.g. `'command' => 'php7.4 vendor/bin/githooks'`).
