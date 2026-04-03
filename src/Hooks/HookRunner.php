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

    public function __construct(FlowPreparer $preparer, FlowExecutor $executor, FileUtilsInterface $fileUtils)
    {
        $this->preparer = $preparer;
        $this->executor = $executor;
        $this->fileUtils = $fileUtils;
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

        // Auto-detect fast mode for pre-commit hooks
        $context = ($event === 'pre-commit')
            ? ExecutionContext::forFastMode($this->fileUtils)
            : null;

        $results = [];

        foreach ($refs as $ref) {
            if (!$this->shouldExecute($ref)) {
                continue;
            }

            $target = $ref->getTarget();
            $flow = $config->getFlow($target);

            if ($flow !== null) {
                $plan = $this->preparer->prepare($flow, $config, $context);
                $results[] = $this->executor->execute($plan);
                continue;
            }

            // Not a flow — try as a direct job
            $jobConfig = $config->getJob($target);

            if ($jobConfig !== null) {
                $plan = $this->preparer->prepareSingleJob($jobConfig, $config->getGlobalOptions(), $context);
                $results[] = $this->executor->execute($plan);
                continue;
            }

            // Neither flow nor job — skip (validation should have caught this)
        }

        return $results;
    }

    /**
     * Evaluate conditions on a HookRef. Both conditions are AND-ed.
     */
    private function shouldExecute(HookRef $ref): bool
    {
        if (!$ref->hasConditions()) {
            return true;
        }

        $branches = $ref->getOnlyOnBranches();
        if (!empty($branches)) {
            $currentBranch = $this->fileUtils->getCurrentBranch();
            if (!$this->matchesBranch($currentBranch, $branches)) {
                return false;
            }
        }

        $filePatterns = $ref->getOnlyFiles();
        $excludePatterns = $ref->getExcludeFiles();
        if (!empty($filePatterns) || !empty($excludePatterns)) {
            $stagedFiles = $this->fileUtils->getModifiedFiles();
            if (!$this->matchesFiles($stagedFiles, $filePatterns, $excludePatterns)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string[] $patterns Branch names or glob patterns
     */
    private function matchesBranch(string $branch, array $patterns): bool
    {
        if ($branch === '') {
            return false;
        }
        foreach ($patterns as $pattern) {
            if ($branch === $pattern || fnmatch($pattern, $branch)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string[] $files Staged file paths
     * @param string[] $includePatterns Glob patterns for inclusion (e.g. '*.php', 'src/**')
     * @param string[] $excludePatterns Glob patterns for exclusion (always prevails over inclusion)
     */
    private function matchesFiles(array $files, array $includePatterns, array $excludePatterns = []): bool
    {
        foreach ($files as $file) {
            $matched = empty($includePatterns);
            foreach ($includePatterns as $pattern) {
                if (fnmatch($pattern, $file, FNM_PATHNAME)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                continue;
            }
            $excluded = false;
            foreach ($excludePatterns as $pattern) {
                if (fnmatch($pattern, $file, FNM_PATHNAME)) {
                    $excluded = true;
                    break;
                }
            }
            if (!$excluded) {
                return true;
            }
        }
        return false;
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
