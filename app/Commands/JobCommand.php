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
use Wtyd\GitHooks\Execution\FlowPreparer;
use Wtyd\GitHooks\Utils\FileUtilsInterface;

class JobCommand extends Command
{
    use FormatsOutput;

    protected $signature = 'job
                            {name : The job to execute}
                            {--fail-fast : Stop on failure}
                            {--ignore-errors-on-exit : Continue even if the job fails}
                            {--format= : Output format (text, json, junit, codeclimate, sarif)}
                            {--output= : Write the structured payload to PATH (default: stdout)}
                            {--dry-run : Show commands without executing}
                            {--fast : Fast mode — accelerable jobs analyze only staged files instead of full paths}
                            {--fast-branch : Fast-branch mode — accelerable jobs analyze branch diff files instead of full paths}
                            {--no-ci : Disable auto-detection of CI environment annotations}
                            {--show-progress : Force progress emission on stderr even when not a TTY (useful for CI with --format=json|junit|sarif|codeclimate)}
                            {--config= : Path to configuration file}';

    protected $description = 'Execute a single job defined in the configuration file';

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

            $cliExtraArgs = $this->getCliExtraArguments();

            $plan = $this->preparer->prepareSingleJob($jobConfig, $config->getGlobalOptions(), $context, $invocationMode, $cliExtraArgs);

            $this->applyFormat($this->executor, $plan);

            $result = $this->executor->execute($plan, (bool) $this->option('dry-run'));

            $this->renderFormattedResult($result);

            return $result->isSuccess() ? 0 : 1;
        } catch (GitHooksExceptionInterface $e) {
            $this->error($e->getMessage());
            return 1;
        }
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
