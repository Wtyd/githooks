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

    /** @var array<string, array{type: string, reason: string, paths: string[], accelerable?: bool}> */
    private array $skippedJobs = [];

    private string $executionMode;

    private ?InputFilesResolution $inputFiles;

    /** @var string[]|null Normal flow names after meta-flow expansion (null for `flow X` and single-flow degenerate) */
    private ?array $expandedFlows;

    private ?EffectiveOptionsResolution $effectiveOptions;

    /**
     * @param JobAbstract[] $jobs
     * @param array<string, array{type: string, reason: string, paths: string[], accelerable?: bool}> $skippedJobs
     * @param string[]|null $expandedFlows
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Immutable plan aggregator.
     */
    public function __construct(
        string $flowName,
        array $jobs,
        OptionsConfiguration $options,
        ?ExecutionContext $context = null,
        array $skippedJobs = [],
        string $executionMode = ExecutionMode::FULL,
        ?InputFilesResolution $inputFiles = null,
        ?array $expandedFlows = null,
        ?EffectiveOptionsResolution $effectiveOptions = null
    ) {
        $this->flowName = $flowName;
        $this->jobs = $jobs;
        $this->options = $options;
        $this->context = $context;
        $this->skippedJobs = $skippedJobs;
        $this->executionMode = $executionMode;
        $this->inputFiles = $inputFiles;
        $this->expandedFlows = $expandedFlows;
        $this->effectiveOptions = $effectiveOptions;
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

    /** @return array<string, array{type: string, reason: string, paths: string[], accelerable?: bool}> */
    public function getSkippedJobs(): array
    {
        return $this->skippedJobs;
    }

    public function getExecutionMode(): string
    {
        return $this->executionMode;
    }

    public function getInputFiles(): ?InputFilesResolution
    {
        return $this->inputFiles;
    }

    /**
     * @return string[]|null Normal flow names after meta-flow expansion;
     *                      null for `flow X` and `flows X` single-flow degenerate runs.
     */
    public function getExpandedFlows(): ?array
    {
        return $this->expandedFlows;
    }

    public function getEffectiveOptions(): ?EffectiveOptionsResolution
    {
        return $this->effectiveOptions;
    }

    /**
     * Return a clone with the given EffectiveOptionsResolution attached.
     */
    public function withEffectiveOptions(EffectiveOptionsResolution $resolution): self
    {
        return new self(
            $this->flowName,
            $this->jobs,
            $this->options,
            $this->context,
            $this->skippedJobs,
            $this->executionMode,
            $this->inputFiles,
            $this->expandedFlows,
            $resolution
        );
    }
}
