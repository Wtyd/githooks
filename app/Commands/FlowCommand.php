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
use Wtyd\GitHooks\Execution\FlowRunner;
use Wtyd\GitHooks\Execution\FlowRunRequest;
use Wtyd\GitHooks\Execution\InputFilesResolver;

/**
 * Thin CLI adapter for `githooks flow <name>`. Phase 2c reduces handle() to
 * pre-flight validation + build DTOs + delegate to {@see FlowRunner::run()}.
 */
class FlowCommand extends Command
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

    protected $signature = 'flow
                            {name : The flow to execute}
                            {--fail-fast : Stop on first job failure}
                            {--processes= : Number of parallel processes}
                            {--exclude-jobs= : Comma-separated list of jobs to skip}
                            {--only-jobs= : Comma-separated list of jobs to run (others skipped)}
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
                            {--monitor : Show thread usage report after execution}
                            {--warn-after= : Warn when total job time (seconds) reaches this threshold}
                            {--fail-after= : Fail when total job time (seconds) reaches this threshold}
                            {--no-time-budget : Disable time-budget evaluation for this run (per-job and flow)}
                            {--memory-warn-above= : Warn when peak simultaneous RSS (MB) crosses this threshold}
                            {--memory-fail-above= : Fail when peak simultaneous RSS (MB) crosses this threshold}
                            {--no-memory-budget : Disable memory-budget evaluation for this run (per-job and flow)}
                            {--allocator= : Resource admission strategy (fifo|greedy)}
                            {--stats : Print a final stats table with peak cores/memory per job and emit the stats block in JSON v2}
                            {--no-ci : Disable auto-detection of CI environment annotations}
                            {--show-progress : Force progress emission on stderr even when not a TTY (useful for CI with --format=json|junit|sarif|codeclimate)}
                            {--branch= : Override the detected branch name used to evaluate flow.on rules (FEAT-2)}
                            {--config= : Path to configuration file}';

    protected $description = 'Execute a flow (group of jobs) defined in the configuration file';

    private InputFilesResolver $inputFilesResolver;

    public function __construct(InputFilesResolver $inputFilesResolver)
    {
        parent::__construct();
        $this->ignoreValidationErrors();
        $this->inputFilesResolver = $inputFilesResolver;
    }

    public function handle(): int
    {
        if ($this->inputContainsDashDashSeparator()) {
            $this->error(
                "The 'flow' command does not support the '--' separator. "
                . "Use 'githooks job <name> -- <args>' to forward extra args to a tool."
            );
            return 1;
        }

        if (!$this->assertNoUnknownOptionsBeforeDashDash()) {
            return 1;
        }

        if (!$this->assertExecutionModeFlagsExclusive()) {
            return 1;
        }

        try {
            $request = $this->buildRunRequest();
        } catch (GitHooksExceptionInterface $e) {
            $this->emitStderr($e->getMessage());
            return 1;
        }

        // Resolve the runner lazily (not via constructor) so the container
        // bindings active at execution time win — e.g. a test re-binding
        // FileUtilsInterface in setUp reaches FlowRunner's FileUtils. With
        // constructor injection the command is built at bootstrap and would
        // capture the production FileUtils, ignoring later test rebinds.
        return $this->getLaravel()->make(FlowRunner::class)
            ->run($request, $this->output, $this->buildRenderOptions());
    }

    private function buildRunRequest(): FlowRunRequest
    {
        $timeBudget = $this->resolveTimeBudgetFlags();
        $memoryBudget = $this->resolveMemoryBudgetFlags();
        $cliBranch = $this->option('branch');

        return new FlowRunRequest(
            strval($this->argument('name')),
            strval($this->option('config')),
            $this->option('fail-fast') ? true : null,
            $this->option('processes') !== null ? (int) $this->option('processes') : null,
            $this->csvOption('exclude-jobs'),
            $this->csvOption('only-jobs'),
            $this->resolveInputFilesFlags(true),
            $this->resolveInvocationModeFromCli(),
            $timeBudget['warnAfter'],
            $timeBudget['failAfter'],
            $timeBudget['disabled'],
            $memoryBudget['warnAbove'],
            $memoryBudget['failAbove'],
            $memoryBudget['disabled'],
            $this->resolveAllocatorFlag(),
            $this->resolveStatsFlag(),
            is_string($cliBranch) && $cliBranch !== '' ? $cliBranch : null,
            (bool) $this->option('dry-run'),
            (bool) $this->option('monitor')
        );
    }

    /**
     * @return string[]
     */
    private function csvOption(string $name): array
    {
        $value = $this->option($name);
        if (empty($value)) {
            return [];
        }
        return array_map('trim', explode(',', strval($value)));
    }
}
