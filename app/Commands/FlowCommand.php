<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\App\Commands;

use LaravelZero\Framework\Commands\Command;
use Wtyd\GitHooks\App\Commands\Concerns\FormatsOutput;
use Wtyd\GitHooks\Configuration\ConfigurationParser;
use Wtyd\GitHooks\Exception\GitHooksExceptionInterface;
use Wtyd\GitHooks\Execution\ExecutionContext;
use Wtyd\GitHooks\Execution\ExecutionMode;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowPlan;
use Wtyd\GitHooks\Execution\FlowPreparer;
use Wtyd\GitHooks\Utils\FileUtilsInterface;

class FlowCommand extends Command
{
    use FormatsOutput;

    protected $signature = 'flow
                            {name : The flow to execute}
                            {--fail-fast : Stop on first job failure}
                            {--processes= : Number of parallel processes}
                            {--exclude-jobs= : Comma-separated list of jobs to skip}
                            {--only-jobs= : Comma-separated list of jobs to run (others skipped)}
                            {--format= : Output format (text, json, junit)}
                            {--dry-run : Show commands without executing}
                            {--fast : Fast mode — accelerable jobs analyze only staged files instead of full paths}
                            {--fast-branch : Fast-branch mode — accelerable jobs analyze branch diff files instead of full paths}
                            {--monitor : Show thread usage report after execution}
                            {--no-ci : Disable auto-detection of CI environment annotations}
                            {--config= : Path to configuration file}';

    protected $description = 'Execute a flow (group of jobs) defined in the configuration file';

    private ConfigurationParser $parser;

    private FlowPreparer $preparer;

    private FlowExecutor $executor;

    public function __construct(ConfigurationParser $parser, FlowPreparer $preparer, FlowExecutor $executor)
    {
        parent::__construct();
        $this->ignoreValidationErrors();
        $this->parser = $parser;
        $this->preparer = $preparer;
        $this->executor = $executor;
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

            $context = ExecutionContext::create($fileUtils, $mainBranch);

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

            $plan = $this->preparer->prepare($flow, $config, $context, $excludeJobs, $onlyJobs, $invocationMode);

            // CLI options override config values
            $cliFailFast = $this->option('fail-fast') ? true : null;
            $cliProcesses = $this->option('processes') !== null ? (int) $this->option('processes') : null;

            if ($cliFailFast !== null || $cliProcesses !== null) {
                $overriddenOptions = $plan->getOptions()->withOverrides($cliFailFast, $cliProcesses);
                $plan = new FlowPlan(
                    $plan->getFlowName(),
                    $plan->getJobs(),
                    $overriddenOptions,
                    $plan->getContext(),
                    $plan->getSkippedJobs()
                );
            }

            $this->applyFormat($this->executor, $plan);

            $result = $this->executor->execute($plan, (bool) $this->option('dry-run'));

            foreach ($config->getValidation()->getWarnings() as $warning) {
                if (strpos($warning, 'skipped') !== false) {
                    echo "  \e[43m\e[30m⏩ $warning\033[0m\n";
                } else {
                    $this->warn($warning);
                }
            }

            $this->renderFormattedResult($result);

            if ($this->option('monitor')) {
                $this->renderMonitorReport($result);
            }

            return $result->isSuccess() ? 0 : 1;
        } catch (GitHooksExceptionInterface $e) {
            $this->error($e->getMessage());
            return 1;
        }
    }
}
