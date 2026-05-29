<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Hooks;

use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use Wtyd\GitHooks\Configuration\ConfigurationParser;
use Wtyd\GitHooks\Configuration\ConfigurationResult;
use Wtyd\GitHooks\Configuration\HookRef;
use Wtyd\GitHooks\Execution\Concerns\EmitsRunnerStderr;
use Wtyd\GitHooks\Execution\ExecutionContext;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowPreparer;
use Wtyd\GitHooks\Execution\FlowResult;
use Wtyd\GitHooks\Utils\FileUtilsInterface;

/**
 * Resolves a hook event to its flows/jobs and executes them in order.
 */
class HookRunner
{
    use EmitsRunnerStderr;

    private FlowPreparer $preparer;

    private FlowExecutor $executor;

    private FileUtilsInterface $fileUtils;

    private PatternMatcher $patternMatcher;

    private ?ConfigurationParser $parser;

    public function __construct(
        FlowPreparer $preparer,
        FlowExecutor $executor,
        FileUtilsInterface $fileUtils,
        ?PatternMatcher $patternMatcher = null,
        ?ConfigurationParser $parser = null
    ) {
        $this->preparer = $preparer;
        $this->executor = $executor;
        $this->fileUtils = $fileUtils;
        $this->patternMatcher = $patternMatcher ?? new PatternMatcher();
        $this->parser = $parser;
    }

    /**
     * High-level entry point: parse the config, validate v3/non-error, and
     * delegate to {@see run()}. Used by HookRunCommand as a thin adapter.
     *
     * @param OutputInterface $output Sink for stderr-routed warnings/errors.
     * @return int Process exit code (0 success, 1 failure).
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Each branch is one class of
     *   pre-execution error or warning the operator must see.
     */
    public function runEvent(string $event, string $configFile, OutputInterface $output): int
    {
        if ($this->parser === null) {
            throw new \LogicException(
                'HookRunner::runEvent() requires a ConfigurationParser. ' .
                'Inject one via the constructor or use the lower-level run() entry point.'
            );
        }

        try {
            $config = $this->parser->parse($configFile);

            if ($config->isLegacy()) {
                $this->emitError($output, "hook:run requires v3 configuration format (hooks/flows/jobs).");
                return 1;
            }

            if ($config->hasErrors()) {
                foreach ($config->getValidation()->getErrors() as $error) {
                    $this->emitError($output, $error);
                }
                return 1;
            }

            if ($config->getHooks() === null) {
                $output->writeln("<comment>No 'hooks' section found in configuration. Nothing to run.</comment>");
                return 0;
            }

            $results = $this->run($event, $config);

            if (empty($results)) {
                $conditionWarning = $this->extractConditionWarning($config);

                if ($conditionWarning !== '') {
                    $output->writeln("<comment>$conditionWarning</comment>");
                } else {
                    $output->writeln("<comment>No flows or jobs configured for event '$event'.</comment>");
                }
                return 0;
            }

            return $this->exitCode($results);
        } catch (Throwable $e) {
            $this->emitError($output, $e->getMessage());
            return 1;
        }
    }

    /**
     * Surface the "all skipped by execution conditions" warning emitted by
     * {@see run()} when every ref matched but was filtered out by only-on /
     * exclude-on / only-files / exclude-files.
     */
    private function extractConditionWarning(ConfigurationResult $config): string
    {
        foreach ($config->getValidation()->getWarnings() as $warning) {
            if (strpos($warning, 'skipped by execution conditions') !== false) {
                return $warning;
            }
        }
        return '';
    }

    /**
     * Run all flows/jobs associated with a hook event.
     *
     * @return FlowResult[] One result per flow/job executed
     */
    public function run(string $event, ConfigurationResult $config): array
    {
        $hooks = $config->getHooks();

        if ($hooks === null) {
            return [];
        }

        $refs = $hooks->resolve($event);

        if (empty($refs)) {
            return [];
        }

        // Create lazy context — does not force any execution mode.
        // Execution mode is determined per-job via config (HookRef, flow, job).
        $mainBranch = $config->getGlobalOptions()->getMainBranch()
            ?? $this->fileUtils->detectMainBranch();
        $context = ExecutionContext::create($this->fileUtils, $mainBranch);

        $results = [];
        $skippedByConditions = 0;

        foreach ($refs as $ref) {
            if (!$this->shouldExecute($ref)) {
                $skippedByConditions++;
                continue;
            }

            $result = $this->executeRef($ref, $config, $context);
            if ($result !== null) {
                $results[] = $result;
            }
        }

        if (empty($results) && $skippedByConditions > 0) {
            $config->getValidation()->addWarning(
                "All hook refs for event '$event' were skipped by execution conditions (only-on, exclude-on, only-files, exclude-files)."
            );
        }

        return $results;
    }

    /**
     * Execute a single HookRef (flow or job).
     */
    private function executeRef(HookRef $ref, ConfigurationResult $config, ?ExecutionContext $context): ?FlowResult
    {
        $target = $ref->getTarget();
        $invocationMode = $ref->getExecution();

        $flow = $config->getFlow($target);

        if ($flow !== null) {
            $plan = $this->preparer->prepare($flow, $config, $context, [], [], $invocationMode);
            return $this->executor->execute($plan);
        }

        $jobConfig = $config->getJob($target);

        if ($jobConfig !== null) {
            $plan = $this->preparer->prepareSingleJob($jobConfig, $config->getGlobalOptions(), $context, $invocationMode);
            return $this->executor->execute($plan);
        }

        return null;
    }

    /**
     * Evaluate conditions on a HookRef. All conditions are AND-ed.
     */
    private function shouldExecute(HookRef $ref): bool
    {
        if (!$ref->hasConditions()) {
            return true;
        }

        $includeBranches = $ref->getOnlyOnBranches();
        $excludeBranches = $ref->getExcludeOnBranches();
        if (!empty($includeBranches) || !empty($excludeBranches)) {
            $currentBranch = $this->fileUtils->getCurrentBranch();
            if (!$this->patternMatcher->matchesBranch($currentBranch, $includeBranches, $excludeBranches)) {
                return false;
            }
        }

        $filePatterns = $ref->getOnlyFiles();
        $excludePatterns = $ref->getExcludeFiles();
        if (!empty($filePatterns) || !empty($excludePatterns)) {
            $stagedFiles = $this->fileUtils->getModifiedFiles();
            if (!$this->patternMatcher->matchesFiles($stagedFiles, $filePatterns, $excludePatterns)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns the overall exit code: 0 if all passed, 1 if any failed.
     *
     * @param FlowResult[] $results
     */
    public function exitCode(array $results): int
    {
        foreach ($results as $result) {
            if (!$result->isSuccess()) {
                return 1;
            }
        }
        return 0;
    }
}
