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
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Branch/file matching logic adds necessary private methods
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
        $flow = $config->getFlow($target);

        if ($flow !== null) {
            $plan = $this->preparer->prepare($flow, $config, $context);
            return $this->executor->execute($plan);
        }

        $jobConfig = $config->getJob($target);

        if ($jobConfig !== null) {
            $plan = $this->preparer->prepareSingleJob($jobConfig, $config->getGlobalOptions(), $context);
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
            if (!$this->matchesBranch($currentBranch, $includeBranches, $excludeBranches)) {
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
     * @param string[] $includePatterns Branch names or glob patterns for inclusion
     * @param string[] $excludePatterns Branch names or glob patterns for exclusion (always prevails)
     */
    private function matchesBranch(string $branch, array $includePatterns, array $excludePatterns = []): bool
    {
        if ($branch === '') {
            return false;
        }

        $matched = empty($includePatterns);
        foreach ($includePatterns as $pattern) {
            if ($branch === $pattern || fnmatch($pattern, $branch)) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            return false;
        }

        foreach ($excludePatterns as $pattern) {
            if ($branch === $pattern || fnmatch($pattern, $branch)) {
                return false;
            }
        }

        return true;
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
                if ($this->fileMatchesPattern($file, $pattern)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                continue;
            }
            $excluded = false;
            foreach ($excludePatterns as $pattern) {
                if ($this->fileMatchesPattern($file, $pattern)) {
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
     * Match a file path against a glob pattern. Supports ** for recursive directory matching.
     * Without **, uses fnmatch with FNM_PATHNAME (* does not cross /).
     */
    private function fileMatchesPattern(string $file, string $pattern): bool
    {
        if (strpos($pattern, '**') === false) {
            return fnmatch($pattern, $file, FNM_PATHNAME);
        }

        return (bool) preg_match($this->globToRegex($pattern), $file);
    }

    /**
     * Convert a glob pattern with double-star support to a regex.
     *
     * Supports: double-star between slashes (zero or more dirs), double-star at end
     * (everything below), single star (anything except /), ? (one char except /).
     */
    private function globToRegex(string $pattern): string
    {
        $segments = explode('**', $pattern);

        $regexSegments = array_map(function (string $seg): string {
            return strtr(preg_quote($seg, '#'), [
                '\\*' => '[^/]*',
                '\\?' => '[^/]',
            ]);
        }, $segments);

        $regex = $regexSegments[0];
        for ($i = 1, $count = count($regexSegments); $i < $count; $i++) {
            $right = $regexSegments[$i];
            $leftEndsSlash = substr($regex, -1) === '/';
            $rightStartsSlash = isset($right[0]) && $right[0] === '/';

            if ($leftEndsSlash && $rightStartsSlash) {
                $regex = substr($regex, 0, -1) . '(?:/.+/|/)' . substr($right, 1);
            } elseif ($leftEndsSlash) {
                $regex .= '.*' . $right;
            } elseif ($rightStartsSlash) {
                $regex .= '(?:.*/)?' . substr($right, 1);
            } else {
                $regex .= '.*' . $right;
            }
        }

        return '#^' . $regex . '$#';
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
