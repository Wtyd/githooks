<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Hooks;

use Wtyd\GitHooks\Configuration\ConfigurationResult;
use Wtyd\GitHooks\Configuration\HookRef;
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
    private FlowPreparer $preparer;

    private FlowExecutor $executor;

    private FileUtilsInterface $fileUtils;

    private PatternMatcher $patternMatcher;

    public function __construct(FlowPreparer $preparer, FlowExecutor $executor, FileUtilsInterface $fileUtils, ?PatternMatcher $patternMatcher = null)
    {
        $this->preparer = $preparer;
        $this->executor = $executor;
        $this->fileUtils = $fileUtils;
        $this->patternMatcher = $patternMatcher ?? new PatternMatcher();
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
