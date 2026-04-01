<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

use Wtyd\GitHooks\Configuration\ConfigurationResult;
use Wtyd\GitHooks\Configuration\FlowConfiguration;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Jobs\JobAbstract;
use Wtyd\GitHooks\Jobs\JobRegistry;

/**
 * Pure function: resolves a flow configuration into an executable plan (list of job instances).
 */
class FlowPreparer
{
    private JobRegistry $jobRegistry;

    public function __construct(JobRegistry $jobRegistry)
    {
        $this->jobRegistry = $jobRegistry;
    }

    /**
     * @param string[] $excludeJobs Job names to exclude from the plan
     */
    public function prepare(FlowConfiguration $flow, ConfigurationResult $config, ?ExecutionContext $context = null, array $excludeJobs = []): FlowPlan
    {
        $options = $flow->getOptions() ?? $config->getGlobalOptions();

        $jobs = [];

        foreach ($flow->getJobs() as $jobName) {
            if (in_array($jobName, $excludeJobs, true)) {
                continue;
            }
            $jobConfig = $config->getJob($jobName);
            if ($jobConfig === null) {
                continue;
            }
            $jobs[] = $this->jobRegistry->create($jobConfig);
        }

        return new FlowPlan($flow->getName(), $jobs, $options, $context);
    }

    /**
     * Prepare a single job for direct execution (githooks job <name>).
     */
    public function prepareSingleJob(JobConfiguration $jobConfig, OptionsConfiguration $options, ?ExecutionContext $context = null): FlowPlan
    {
        $job = $this->jobRegistry->create($jobConfig);
        return new FlowPlan($jobConfig->getName(), [$job], $options, $context);
    }
}
