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

    /** @var array<string, array{type: string, reason: string, paths: string[]}> */
    private array $skippedJobs = [];

    /**
     * @param JobAbstract[] $jobs
     * @param array<string, array{type: string, reason: string, paths: string[]}> $skippedJobs
     */
    public function __construct(
        string $flowName,
        array $jobs,
        OptionsConfiguration $options,
        ?ExecutionContext $context = null,
        array $skippedJobs = []
    ) {
        $this->flowName = $flowName;
        $this->jobs = $jobs;
        $this->options = $options;
        $this->context = $context;
        $this->skippedJobs = $skippedJobs;
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

    /** @return array<string, array{type: string, reason: string, paths: string[]}> */
    public function getSkippedJobs(): array
    {
        return $this->skippedJobs;
    }
}
