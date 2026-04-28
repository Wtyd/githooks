# Changelog

All notable changes to this project are documented here.

## [3.3.0] (Unreleased)

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
- **REQ-018 warning**: when a flow or alias's `options` block is ignored because of the run mode, `flows` emits a one-line notice naming the ignored sources so the operator knows what is and isn't being applied.

#### Cross-cutting conditions header + `effectiveOptions`

A new conditions header is emitted at the start of every `flow`, `flows` and `job` run to make the active options visible at a glance:

```
Settings: processes=4 (cli) | fail-fast=true (flows.ci-pack.options) | mode=full (default)
Flows: qa, lint
```

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
- **CLI overrides**: `--warn-after=N`, `--fail-after=N` (flow-level on `flow` / `flows`; job-level on `job` per spec REQ-016). `--no-time-budget` disables both layers for that run; mixing it with `--warn-after` emits a warning on stderr.
- **JSON v2 (explicit-null pattern)**: a new root `timeBudget` field (object or `null`) and per-job `threshold` field (object or `null`) are always present. Consumers can write `if (job.threshold) { … }` without existence checks. `reason` is a string when warned/failed is `true`, `null` otherwise.
- **Conditions header**: extended with a `time-budget=...` segment showing the effective values and their origin (`flows.options`, `flows.<X>.options`, `cli`, `default`).
- **`conf:check` validation**: rejects non-positive integers, `warn-after >= fail-after`, `time-budget` placed inside a job; warns on unknown keys with did-you-mean suggestions.
- **Differentiator**: GrumPHP, CaptainHook, lefthook, pre-commit (Yelp) and golangci-lint do not expose a declarative time budget at job + aggregate level.

Spec: [spec/spec-design-time-budget-thresholds.md](../spec/spec-design-time-budget-thresholds.md).

#### Memory budget + 2D allocator + RSS sampler (Linux)

GitHooks now declaratively watches RSS consumption per job and across the whole flow, schedules admissions in 2D (cores + memory) when both axes are constrained, and surfaces peaks in a canonical `--stats` table — none of GrumPHP, CaptainHook, lefthook, pre-commit or golangci-lint expose this combination.

- **Per-job memory threshold** (`jobs.<name>.memory`): two equivalent forms.
  - Short form `memory: 2000` (MB) — single warn threshold AND scheduler reservation when a flow `memory-budget` is declared.
  - Extended form `memory: { warn-above: 1500, fail-above: 2000 }` — explicit thresholds, no reservation.
  - Crossing `warn-above` annotates `⚠`; crossing `fail-above` flips the job to KO with exit `1` even when the tool itself returned `0`.
- **Flow `memory-budget`** (`flows.options.memory-budget` or per-flow): observational watchdog over the simultaneous RSS sum across jobs in flight. Crossing `fail-above` **kills jobs in flight** (`process->stop(0)`) and skips the queued ones with reason `"flow memory-budget exceeded"`. The flow exits 1 even if every individual job had passed (the conceptual key of the feature).
- **2D allocator** (`flows.options.allocator: fifo|greedy`): when a `memory-budget` is declared **and** at least one job has a short-form `memory:` reservation, the pool admits jobs only when both cores and memory fit. FIFO blocks the entire queue when the head does not fit; greedy scans for the first fitting job (REQ-019). 1D mode (cores only) is preserved when either side of the precondition is missing.
- **RSS sampler**: Linux via `/proc/<PID>/status` walked across the process tree (root + descendants — Symfony's shell wrapper alone is ~1 MB; the actual analyzers are children); macOS via a single `ps -o pid=,ppid=,rss= -ax` invocation per tick. Polled every 1 second while jobs are in flight. Windows degrades gracefully — a short stderr warning (`⚠ Memory budget disabled: RSS sampling not available on Windows`) disables thresholds; the 2D allocator still schedules from declared `memory:` reservations and `--stats` still emits the cores axis.
- **`--stats` table**: 5-column summary (Job / Status / Time / Peak Cores / Peak Memory) with a TOTAL row + temporal attribution lines `Memory peak at Xs: jobA Pmb + jobB Pmb...` and `Cores peak at Xs:  jobA + jobB...`. Active when `--stats` (CLI) or `stats: true` (config).
- **CLI overrides**: `--memory-warn-above=N`, `--memory-fail-above=N`, `--no-memory-budget`, `--allocator=fifo|greedy`, `--stats`. Apply flow-level except in `githooks job` where they apply to the single job.
- **JSON v2**: new root-level `memoryBudget` and `stats` blocks (always present under the explicit-null pattern), per-job `memoryReserved`, `memoryPeak`, `memoryThreshold` and `killedReason`. SARIF / JUnit / Code Climate are unchanged in this iteration (REQ-042).
- **Conditions header**: extended with `memory-budget=warn-above=WMB,fail-above=FMB (origin)`, `allocator=fifo|greedy (origin)` and `stats=true|false (origin)` segments alongside `time-budget`.
- **`conf:check` validation**: positive-integer guards, warn/fail ordering, `memory > memory-budget.warn-above` (could-never-run), unknown allocator values, `memory-budget` typo suggestions.

Spec: [spec/spec-design-memory-budget.md](../spec/spec-design-memory-budget.md).

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

- **Per-job `cores` reservation ([`cores`](configuration/jobs.md#reserving-cores-explicitly-cores))**: every job can declare `cores: N` to reserve N slots in the thread budget. Controllable tools (phpcs, psalm, parallel-lint, paratest) automatically receive their native threading flag (`--parallel`, `--threads`, `-j`, `--processes`) with the same value, so you configure parallelism once per job regardless of the tool. Budget-only tools (phpstan, custom jobs) use `cores` to keep the `--monitor` peak accurate without forcing worker count. `conf:check` warns when `cores` coexists with a tool's native threading flag.

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
