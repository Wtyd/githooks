<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Hooks;

use Wtyd\GitHooks\Configuration\ConfigurationResult;
use Wtyd\GitHooks\Configuration\FlowConfiguration;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowPreparer;
use Wtyd\GitHooks\Execution\FlowResult;

/**
 * Resolves a hook event to its flows/jobs and executes them in order.
 */
class HookRunner
{
    private FlowPreparer $preparer;

    private FlowExecutor $executor;

    public function __construct(FlowPreparer $preparer, FlowExecutor $executor)
    {
        $this->preparer = $preparer;
        $this->executor = $executor;
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

        $results = [];

        foreach ($refs as $ref) {
            $flow = $config->getFlow($ref);

            if ($flow !== null) {
                $plan = $this->preparer->prepare($flow, $config);
                $results[] = $this->executor->execute($plan);
                continue;
            }

            // Not a flow — try as a direct job
            $jobConfig = $config->getJob($ref);

            if ($jobConfig !== null) {
                $plan = $this->preparer->prepareSingleJob($jobConfig, $config->getGlobalOptions());
                $results[] = $this->executor->execute($plan);
                continue;
            }

            // Neither flow nor job — skip (validation should have caught this)
        }

        return $results;
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
