<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\App\Commands;

use LaravelZero\Framework\Commands\Command;
use Wtyd\GitHooks\Execution\{
    EffectiveOptionsResolver,
    ExecutionContext,
    ExecutionMode,
    FlowExecutor,
    FlowPlan,
    FlowPreparer,
    InputFilesResolver
};
use Wtyd\GitHooks\App\Commands\Concerns\EmitsConditionsHeader;
use Wtyd\GitHooks\App\Commands\Concerns\FormatsOutput;
use Wtyd\GitHooks\App\Commands\Concerns\ResolvesInputFiles;
use Wtyd\GitHooks\Configuration\ConfigurationParser;
use Wtyd\GitHooks\Exception\GitHooksExceptionInterface;
use Wtyd\GitHooks\Utils\FileUtilsInterface;

class JobCommand extends Command
{
    use EmitsConditionsHeader;
    use FormatsOutput;
    use ResolvesInputFiles;

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
                            {--files= : CSV of files to filter accelerable jobs by (mutually exclusive with --files-from)}
                            {--files-from= : Path to a manifest file with one path per line (mutually exclusive with --files)}
                            {--exclude-pattern= : CSV of glob patterns excluded from --files / --files-from input}
                            {--no-ci : Disable auto-detection of CI environment annotations}
                            {--show-progress : Force progress emission on stderr even when not a TTY (useful for CI with --format=json|junit|sarif|codeclimate)}
                            {--config= : Path to configuration file}';

    protected $description = 'Execute a single job defined in the configuration file';

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

            $inputFilesResolution = $this->resolveInputFilesFlags();

            if ($inputFilesResolution !== null) {
                $context = ExecutionContext::forInputFiles($inputFilesResolution, $fileUtils);
                $invocationMode = ExecutionMode::FAST;
            } else {
                $context = ExecutionContext::create($fileUtils, $mainBranch);
            }

            $cliExtraArgs = $this->getCliExtraArguments();

            $cliFailFast = $this->hasOption('fail-fast') && (bool) $this->option('fail-fast') ? true : null;
            $cliProcesses = null;

            $resolver = new EffectiveOptionsResolver();
            $resolution = $resolver->resolveMultiple($config, $cliFailFast, $cliProcesses, $invocationMode);

            $plan = $this->preparer->prepareSingleJob($jobConfig, $resolution->getOptions(), $context, $invocationMode, $cliExtraArgs);

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

            $this->emitConditionsHeader($resolution, null, $plan->getInputFiles());

            $result = $this->executor->execute($plan, (bool) $this->option('dry-run'));

            $this->renderFormattedResult($result, $plan->getOptions());

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
