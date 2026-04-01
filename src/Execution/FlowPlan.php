<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Jobs\JobAbstract;

/**
 * Immutable plan: the resolved jobs and options for a flow execution.
 */
class FlowPlan
{
    private string $flowName;

    /** @var JobAbstract[] */
    private array $jobs;

    private OptionsConfiguration $options;

    private ?ExecutionContext $context;

    /**
     * @param JobAbstract[] $jobs
     */
    public function __construct(string $flowName, array $jobs, OptionsConfiguration $options, ?ExecutionContext $context = null)
    {
        $this->flowName = $flowName;
        $this->jobs = $jobs;
        $this->options = $options;
        $this->context = $context;
    }

    public function getFlowName(): string
    {
        return $this->flowName;
    }

    /** @return JobAbstract[] */
    public function getJobs(): array
    {
        return $this->jobs;
    }

    public function getOptions(): OptionsConfiguration
    {
        return $this->options;
    }

    public function getContext(): ?ExecutionContext
    {
        return $this->context;
    }
}
