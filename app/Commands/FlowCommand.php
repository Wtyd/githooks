<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\App\Commands;

use LaravelZero\Framework\Commands\Command;
use Wtyd\GitHooks\App\Commands\Concerns\EmitsConditionsHeader;
use Wtyd\GitHooks\App\Commands\Concerns\EmitsConfigWarnings;
use Wtyd\GitHooks\App\Commands\Concerns\FormatsOutput;
use Wtyd\GitHooks\App\Commands\Concerns\ResolvesAllocatorFlag;
use Wtyd\GitHooks\App\Commands\Concerns\ResolvesInputFiles;
use Wtyd\GitHooks\App\Commands\Concerns\ResolvesMemoryBudgetFlags;
use Wtyd\GitHooks\App\Commands\Concerns\ResolvesStatsFlag;
use Wtyd\GitHooks\App\Commands\Concerns\ResolvesTimeBudgetFlags;
use Wtyd\GitHooks\Configuration\ConfigurationParser;
use Wtyd\GitHooks\Exception\GitHooksExceptionInterface;
use Wtyd\GitHooks\Execution\EffectiveOptionsResolver;
use Wtyd\GitHooks\Execution\ExecutionContext;
use Wtyd\GitHooks\Execution\ExecutionMode;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowPlan;
use Wtyd\GitHooks\Execution\FlowPreparer;
use Wtyd\GitHooks\Execution\InputFilesResolver;
use Wtyd\GitHooks\Utils\FileUtilsInterface;

class FlowCommand extends Command
{
    use EmitsConditionsHeader;
    use EmitsConfigWarnings;
    use FormatsOutput;
    use ResolvesAllocatorFlag;
    use ResolvesInputFiles;
    use ResolvesMemoryBudgetFlags;
    use ResolvesStatsFlag;
    use ResolvesTimeBudgetFlags;

    protected $signature = 'flow
                            {name : The flow to execute}
                            {--fail-fast : Stop on first job failure}
                            {--processes= : Number of parallel processes}
                            {--exclude-jobs= : Comma-separated list of jobs to skip}
                            {--only-jobs= : Comma-separated list of jobs to run (others skipped)}
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
                            {--config= : Path to configuration file}';

    protected $description = 'Execute a flow (group of jobs) defined in the configuration file';

    private ConfigurationParser $parser;

    private FlowPreparer $preparer;

    private FlowExecutor $executor;

    private InputFilesResolver $inputFilesResolver;

    public function __construct(
        ConfigurationParser $parser,
        FlowPreparer $preparer,
        FlowExecutor $executor,
        InputFilesResolver $inputFilesResolver
    ) {
        parent::__construct();
        $this->ignoreValidationErrors();
        $this->parser = $parser;
        $this->preparer = $preparer;
        $this->executor = $executor;
        $this->inputFilesResolver = $inputFilesResolver;
    }

    public function handle(): int
    {
        $flowName = strval($this->argument('name'));
        $configFile = strval($this->option('config'));

        try {
            $config = $this->parser->parse($configFile);

            if ($config->isLegacy()) {
                $this->error("The 'flow' command requires v3 configuration format (hooks/flows/jobs).");
                $this->warn("Use 'githooks conf:init' to generate the new format.");
                return 1;
            }

            if ($config->hasErrors()) {
                foreach ($config->getValidation()->getErrors() as $error) {
                    $this->error($error);
                }
                return 1;
            }

            $flow = $config->getFlow($flowName);

            if ($flow === null) {
                $this->error("Flow '$flowName' is not defined in the configuration file.");
                $availableFlows = array_keys($config->getFlows());
                if (!empty($availableFlows)) {
                    $this->info('Available flows: ' . implode(', ', $availableFlows));
                }
                return 1;
            }

            $fileUtils = $this->getLaravel()->make(FileUtilsInterface::class);

            $invocationMode = null;
            if ($this->option('fast')) {
                $invocationMode = ExecutionMode::FAST;
            } elseif ($this->option('fast-branch')) {
                $invocationMode = ExecutionMode::FAST_BRANCH;
            }

            $mainBranch = $config->getGlobalOptions()->getMainBranch()
                ?? $fileUtils->detectMainBranch();

            $inputFilesResolution = $this->resolveInputFilesFlags(true);

            if ($inputFilesResolution !== null) {
                $context = ExecutionContext::forInputFiles($inputFilesResolution, $fileUtils);
                $invocationMode = ExecutionMode::FAST;
            } else {
                $context = ExecutionContext::create($fileUtils, $mainBranch);
            }

            $excludeJobs = [];
            $excludeOption = $this->option('exclude-jobs');
            if (!empty($excludeOption)) {
                $excludeJobs = array_map('trim', explode(',', strval($excludeOption)));
            }

            $onlyJobs = [];
            $onlyOption = $this->option('only-jobs');
            if (!empty($onlyOption)) {
                $onlyJobs = array_map('trim', explode(',', strval($onlyOption)));
            }

            if (!empty($excludeJobs) && !empty($onlyJobs)) {
                $this->error('Options --exclude-jobs and --only-jobs cannot be used together.');
                return 1;
            }

            // CLI options for the per-key effective-options cascade
            $cliFailFast = $this->option('fail-fast') ? true : null;
            $cliProcesses = $this->option('processes') !== null ? (int) $this->option('processes') : null;
            $timeBudgetFlags = $this->resolveTimeBudgetFlags();
            $memoryBudgetFlags = $this->resolveMemoryBudgetFlags();
            $cliAllocator = $this->resolveAllocatorFlag();
            $cliStats = $this->resolveStatsFlag();

            $resolver = new EffectiveOptionsResolver();
            $resolution = $resolver->resolveSingle(
                $config,
                $flow,
                $cliFailFast,
                $cliProcesses,
                $invocationMode,
                $timeBudgetFlags['warnAfter'],
                $timeBudgetFlags['failAfter'],
                $timeBudgetFlags['disabled'],
                $memoryBudgetFlags['warnAbove'],
                $memoryBudgetFlags['failAbove'],
                $memoryBudgetFlags['disabled'],
                $cliAllocator,
                $cliStats
            );

            $plan = $this->preparer->prepare($flow, $config, $context, $excludeJobs, $onlyJobs, $invocationMode);

            // Replace the plan options with the cascade-resolved ones and attach the trace
            $plan = new FlowPlan(
                $plan->getFlowName(),
                $plan->getJobs(),
                $resolution->getOptions(),
                $plan->getContext(),
                $plan->getSkippedJobs(),
                $plan->getExecutionMode(),
                $plan->getInputFiles(),
                $plan->getExpandedFlows(),
                $resolution
            );

            $this->applyFormat($this->executor, $plan);

            $this->executor->setThresholdsDisabled($timeBudgetFlags['disabled']);
            $this->executor->setMemoryBudgetDisabled($memoryBudgetFlags['disabled']);

            $this->emitConditionsHeader($resolution, $plan->getExpandedFlows(), $plan->getInputFiles());

            $result = $this->executor->execute($plan, (bool) $this->option('dry-run'));
            $result->setConfigValidation($config->getValidation());

            $this->emitConfigWarnings($config->getValidation());

            $this->renderFormattedResult($result, $plan->getOptions());

            if ($this->option('monitor')) {
                $this->renderMonitorReport($result);
            }

            return $result->isSuccess() ? 0 : 1;
        } catch (GitHooksExceptionInterface $e) {
            // To STDERR so --format=json/junit/sarif/codeclimate stdout stays clean (BUG-5).
            fwrite(STDERR, $e->getMessage() . "\n");
            return 1;
        }
    }
}
