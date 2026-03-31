<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\App\Commands;

use LaravelZero\Framework\Commands\Command;
use Wtyd\GitHooks\Configuration\ConfigurationParser;
use Wtyd\GitHooks\Exception\GitHooksExceptionInterface;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowPreparer;

class FlowCommand extends Command
{
    protected $signature = 'flow
                            {name : The flow to execute}
                            {--fail-fast : Stop on first job failure}
                            {--processes= : Number of parallel processes}
                            {--exclude-jobs= : Comma-separated list of jobs to skip}
                            {-c|--config= : Path to configuration file}';

    protected $description = 'Execute a flow (group of jobs) defined in the configuration file';

    private ConfigurationParser $parser;

    private FlowPreparer $preparer;

    private FlowExecutor $executor;

    public function __construct(ConfigurationParser $parser, FlowPreparer $preparer, FlowExecutor $executor)
    {
        parent::__construct();
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

            $plan = $this->preparer->prepare($flow, $config);
            $result = $this->executor->execute($plan);

            foreach ($config->getValidation()->getWarnings() as $warning) {
                $this->warn($warning);
            }

            $total = count($result->getJobResults());
            $passed = $result->getPassedCount();
            $this->line("Results: $passed/$total passed" . ($result->isSuccess() ? ' ✔️' : ''));

            return $result->isSuccess() ? 0 : 1;
        } catch (GitHooksExceptionInterface $e) {
            $this->error($e->getMessage());
            return 1;
        }
    }
}
