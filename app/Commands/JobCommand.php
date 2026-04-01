<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\App\Commands;

use LaravelZero\Framework\Commands\Command;
use Wtyd\GitHooks\App\Commands\Concerns\FormatsOutput;
use Wtyd\GitHooks\Configuration\ConfigurationParser;
use Wtyd\GitHooks\Exception\GitHooksExceptionInterface;
use Wtyd\GitHooks\Execution\ExecutionContext;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowPreparer;
use Wtyd\GitHooks\Utils\FileUtilsInterface;

class JobCommand extends Command
{
    use FormatsOutput;

    protected $signature = 'job
                            {name : The job to execute}
                            {--fail-fast : Stop on failure}
                            {--ignore-errors-on-exit : Continue even if the job fails}
                            {--format= : Output format (text, json, junit)}
                            {--fast : Fast mode — pass staged files to custom jobs via $GITHOOKS_STAGED_FILES}
                            {-c|--config= : Path to configuration file}';

    protected $description = 'Execute a single job defined in the configuration file';

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
        $jobName = strval($this->argument('name'));
        $configFile = strval($this->option('config'));

        try {
            $config = $this->parser->parse($configFile);

            if ($config->isLegacy()) {
                $this->error("The 'job' command requires v3 configuration format (hooks/flows/jobs).");
                $this->warn("Use 'githooks conf:init' to generate the new format.");
                return 1;
            }

            if ($config->hasErrors()) {
                foreach ($config->getValidation()->getErrors() as $error) {
                    $this->error($error);
                }
                return 1;
            }

            $jobConfig = $config->getJob($jobName);

            if ($jobConfig === null) {
                $this->error("Job '$jobName' is not defined in the configuration file.");
                $availableJobs = array_keys($config->getJobs());
                if (!empty($availableJobs)) {
                    $this->info('Available jobs: ' . implode(', ', $availableJobs));
                }
                return 1;
            }

            $this->applyFormat($this->executor);

            $context = $this->option('fast')
                ? ExecutionContext::forFastMode($this->getLaravel()->make(FileUtilsInterface::class))
                : null;

            $plan = $this->preparer->prepareSingleJob($jobConfig, $config->getGlobalOptions(), $context);
            $result = $this->executor->execute($plan);

            $this->renderFormattedResult($result);

            return $result->isSuccess() ? 0 : 1;
        } catch (GitHooksExceptionInterface $e) {
            $this->error($e->getMessage());
            return 1;
        }
    }
}
