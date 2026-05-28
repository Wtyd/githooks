<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\App\Commands;

use LaravelZero\Framework\Commands\Command;
use Wtyd\GitHooks\Execution\{
    FlowExecutor,
    InputFilesResolver,
    JobRunner,
    JobRunRequest
};
use Wtyd\GitHooks\App\Commands\Concerns\AssertsExecutionModeFlagsExclusive;
use Wtyd\GitHooks\App\Commands\Concerns\EmitsConditionsHeader;
use Wtyd\GitHooks\App\Commands\Concerns\EmitsConfigWarnings;
use Wtyd\GitHooks\App\Commands\Concerns\EmitsStderr;
use Wtyd\GitHooks\App\Commands\Concerns\FormatsOutput;
use Wtyd\GitHooks\App\Commands\Concerns\ResolvesAllocatorFlag;
use Wtyd\GitHooks\App\Commands\Concerns\ResolvesInputFiles;
use Wtyd\GitHooks\App\Commands\Concerns\ResolvesMemoryBudgetFlags;
use Wtyd\GitHooks\App\Commands\Concerns\ResolvesStatsFlag;
use Wtyd\GitHooks\App\Commands\Concerns\ResolvesTimeBudgetFlags;
use Wtyd\GitHooks\App\Commands\Concerns\ValidatesUnknownOptionsBeforeDashDash;
use Wtyd\GitHooks\Exception\GitHooksExceptionInterface;

class JobCommand extends Command
{
    use AssertsExecutionModeFlagsExclusive;
    use EmitsConditionsHeader;
    use EmitsConfigWarnings;
    use EmitsStderr;
    use FormatsOutput;
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
                            {--format= : Output format (text, json, junit, codeclimate, sarif)}
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
                            {--warn-after= : Warn when this single job exceeds this duration (seconds)}
                            {--fail-after= : Fail when this single job exceeds this duration (seconds)}
                            {--no-time-budget : Disable time-budget evaluation for this run}
                            {--memory-warn-above= : Warn when this job RSS (MB) crosses this threshold}
                            {--memory-fail-above= : Fail when this job RSS (MB) crosses this threshold}
                            {--no-memory-budget : Disable memory-budget evaluation for this run}
                            {--stats : Print a final stats table with peak cores/memory and emit the stats block in JSON v2}
                            {--config= : Path to configuration file}';

    protected $description = 'Execute a single job defined in the configuration file';

    private FlowExecutor $executor;

    private InputFilesResolver $inputFilesResolver;

    private JobRunner $runner;

    public function __construct(
        FlowExecutor $executor,
        InputFilesResolver $inputFilesResolver,
        JobRunner $runner
    ) {
        parent::__construct();
        $this->ignoreValidationErrors();
        $this->executor = $executor;
        $this->inputFilesResolver = $inputFilesResolver;
        $this->runner = $runner;
    }

    public function handle(): int
    {
        if (!$this->assertNoUnknownOptionsBeforeDashDash()) {
            return 1;
        }

        if (!$this->assertExecutionModeFlagsExclusive()) {
            return 1;
        }

        try {
            $request = $this->buildRunRequest();
            $preparation = $this->runner->prepare($request);

            foreach ($preparation->errors as $error) {
                $this->error($error);
            }
            if (!$preparation->success) {
                return 1;
            }

            $this->applyFormat($this->executor, $preparation->plan);
            $this->executor->setThresholdsDisabled($request->timeBudgetDisabled);
            $this->executor->setMemoryBudgetDisabled($request->memoryBudgetDisabled);

            $this->emitConditionsHeader($preparation->resolution, null, $preparation->plan->getInputFiles());

            $result = $this->executor->execute($preparation->plan, (bool) $this->option('dry-run'));
            $result->setConfigValidation($preparation->config->getValidation());

            $this->emitConfigWarnings($preparation->config->getValidation());

            $this->renderFormattedResult($result, $preparation->plan->getOptions());

            return $result->isSuccess() ? 0 : 1;
        } catch (GitHooksExceptionInterface $e) {
            // To STDERR so --format=json/junit/sarif/codeclimate stdout stays clean (BUG-5).
            $this->emitStderr($e->getMessage());
            return 1;
        }
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
            $this->hasOption('fail-fast') && (bool) $this->option('fail-fast') ? true : null
        );
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
