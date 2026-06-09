<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\App\Commands;

use LaravelZero\Framework\Commands\Command;
use Wtyd\GitHooks\App\Commands\Concerns\AssertsExecutionModeFlagsExclusive;
use Wtyd\GitHooks\App\Commands\Concerns\BuildsRenderOptions;
use Wtyd\GitHooks\App\Commands\Concerns\EmitsStderr;
use Wtyd\GitHooks\App\Commands\Concerns\ResolvesAllocatorFlag;
use Wtyd\GitHooks\App\Commands\Concerns\ResolvesInputFiles;
use Wtyd\GitHooks\App\Commands\Concerns\ResolvesMemoryBudgetFlags;
use Wtyd\GitHooks\App\Commands\Concerns\ResolvesStatsFlag;
use Wtyd\GitHooks\App\Commands\Concerns\ResolvesTimeBudgetFlags;
use Wtyd\GitHooks\App\Commands\Concerns\ValidatesUnknownOptionsBeforeDashDash;
use Wtyd\GitHooks\Exception\GitHooksExceptionInterface;
use Wtyd\GitHooks\Execution\InputFilesResolver;
use Wtyd\GitHooks\Execution\JobRunner;
use Wtyd\GitHooks\Execution\JobRunRequest;

/**
 * Thin CLI adapter for `githooks job <name>`. Phase 2b reduces handle() to
 * three steps: validate pre-execution flags, build the {@see JobRunRequest}
 * + {@see RenderOptions} DTOs from the parsed options, and delegate to
 * {@see JobRunner::run()} which owns the prepare-and-render pipeline.
 *
 * Concerns kept here: argument/option resolution (`Resolves*` traits) and
 * flag mutex assertions. Everything else lives in src/Execution/JobRunner.php
 * and src/Output/*.
 */
class JobCommand extends Command
{
    use AssertsExecutionModeFlagsExclusive;
    use BuildsRenderOptions;
    use EmitsStderr;
    use ResolvesAllocatorFlag;
    use ResolvesInputFiles;
    use ResolvesMemoryBudgetFlags;
    use ResolvesStatsFlag;
    use ResolvesTimeBudgetFlags;
    use ValidatesUnknownOptionsBeforeDashDash;

    protected $signature = 'job
                            {name : The job to execute}
                            {--fail-fast : Stop on failure}
                            {--ignore-errors-on-exit : Continue even if the job fails}
                            {--format= : Output format (text, json, junit, codeclimate, sarif, claude-code)}
                            {--output= : Write the structured payload to PATH (default: stdout)}
                            {--report-json= : Also write a JSON v2 report to PATH}
                            {--report-junit= : Also write a JUnit XML report to PATH}
                            {--report-sarif= : Also write a SARIF 2.1.0 report to PATH}
                            {--report-codeclimate= : Also write a Code Climate JSON report to PATH}
                            {--no-reports : Ignore the `reports` section from config (--report-* flags still apply)}
                            {--dry-run : Show commands without executing}
                            {--fast : Fast mode — accelerable jobs analyze only staged files instead of full paths}
                            {--fast-branch : Fast-branch mode — accelerable jobs analyze branch diff files instead of full paths}
                            {--fast-dirty : Fast-dirty mode — accelerable jobs analyze the entire working tree (staged, unstaged, and untracked non-ignored files)}
                            {--files= : CSV of files to filter accelerable jobs by (mutually exclusive with --files-from)}
                            {--files-from= : Path to a manifest file with one path per line (mutually exclusive with --files)}
                            {--exclude-pattern= : CSV of glob patterns excluded from --files / --files-from input}
                            {--no-ci : Disable auto-detection of CI environment annotations}
                            {--show-progress : Force progress emission on stderr even when not a TTY (useful for CI with --format=json|junit|sarif|codeclimate)}
                            {--diag : Print a runtime diagnostics block (CPU/mem/load/CI/version) before running. Auto-on in CI.}
                            {--warn-after= : Warn when this single job exceeds this duration (seconds)}
                            {--fail-after= : Fail when this single job exceeds this duration (seconds)}
                            {--no-time-budget : Disable time-budget evaluation for this run}
                            {--memory-warn-above= : Warn when this job RSS (MB) crosses this threshold}
                            {--memory-fail-above= : Fail when this job RSS (MB) crosses this threshold}
                            {--no-memory-budget : Disable memory-budget evaluation for this run}
                            {--stats : Print a final stats table with peak cores/memory and emit the stats block in JSON v2}
                            {--stats-sort= : Sort the --stats table: exec (default, completion order), name, or type. Non-exec adds a # column with the execution order}
                            {--message-file= : Path to the commit message file (commit-msg jobs; provided by the git hook as $1)}
                            {--message= : Literal commit message to validate (commit-msg jobs; mutually exclusive with --message-file)}
                            {--config= : Path to configuration file}';

    protected $description = 'Execute a single job defined in the configuration file';

    private InputFilesResolver $inputFilesResolver;

    public function __construct(InputFilesResolver $inputFilesResolver)
    {
        parent::__construct();
        $this->ignoreValidationErrors();
        $this->inputFilesResolver = $inputFilesResolver;
    }

    public function handle(): int
    {
        if (!$this->assertNoUnknownOptionsBeforeDashDash()) {
            return 1;
        }

        if (!$this->assertExecutionModeFlagsExclusive()) {
            return 1;
        }

        if (!$this->assertMessageFlagsExclusive()) {
            return 1;
        }

        try {
            $request = $this->buildRunRequest();
        } catch (GitHooksExceptionInterface $e) {
            // Input-files resolution lives in the Command (via ResolvesInputFiles)
            // because it needs $this->option(). Its exceptions surface here so
            // --format=json/junit/sarif/codeclimate stdout stays clean (BUG-5).
            $this->emitStderr($e->getMessage());
            return 1;
        }

        // Lazy runner resolution (see FlowCommand) so test container rebinds of
        // FileUtilsInterface reach JobRunner's FileUtils at execution time.
        return $this->getLaravel()->make(JobRunner::class)
            ->run($request, $this->output, $this->buildRenderOptions());
    }

    private function buildRunRequest(): JobRunRequest
    {
        $timeBudget = $this->resolveTimeBudgetFlags();
        $memoryBudget = $this->resolveMemoryBudgetFlags();
        return new JobRunRequest(
            strval($this->argument('name')),
            strval($this->option('config')),
            $this->getCliExtraArguments(),
            $this->resolveInputFilesFlags(),
            $this->resolveInvocationModeFromCli(),
            $timeBudget['warnAfter'],
            $timeBudget['failAfter'],
            $timeBudget['disabled'],
            $memoryBudget['warnAbove'],
            $memoryBudget['failAbove'],
            $memoryBudget['disabled'],
            $this->resolveStatsFlag(),
            $this->hasOption('fail-fast') && (bool) $this->option('fail-fast') ? true : null,
            (bool) $this->option('dry-run'),
            $this->resolveCommitMessageFile(),
            $this->hasOption('ignore-errors-on-exit') && (bool) $this->option('ignore-errors-on-exit') ? true : null
        );
    }

    /**
     * --message and --message-file are mutually exclusive (AC-015). Checked
     * before anything runs so the error caps the invocation at exit 1.
     */
    private function assertMessageFlagsExclusive(): bool
    {
        if ($this->option('message') !== null && $this->option('message-file') !== null) {
            $this->emitStderr('--message and --message-file are mutually exclusive.');
            return false;
        }
        return true;
    }

    /**
     * Resolve the commit-message file path from the CLI flags (FEAT-16):
     * `--message-file` wins; `--message` is materialised to a temp file so the
     * rest of the pipeline always deals with a path. Null when neither is given
     * (the job then falls back to the env var or `.git/COMMIT_EDITMSG`).
     */
    private function resolveCommitMessageFile(): ?string
    {
        $file = $this->option('message-file');
        if ($file !== null) {
            return (string) $file;
        }

        $message = $this->option('message');
        if ($message !== null) {
            $path = (string) tempnam(sys_get_temp_dir(), 'githooks-commit-msg-');
            file_put_contents($path, (string) $message);
            return $path;
        }

        return null;
    }

    private function getCliExtraArguments(): string
    {
        $argv = $_SERVER['argv'] ?? [];
        $dashDashIndex = array_search('--', $argv, true);

        if ($dashDashIndex === false) {
            return '';
        }

        $extraParts = array_slice($argv, $dashDashIndex + 1);

        return implode(' ', $extraParts);
    }
}
