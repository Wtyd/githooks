<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

use Wtyd\GitHooks\Configuration\ConfigurationResult;

/**
 * Result of {@see FlowsRunner::prepare()}. Multi-flow variant of
 * {@see FlowPreparation} adding three contextual flags the renderer needs:
 *
 *  - $isSingleFlow:  the run is a single normal flow (degenerate multi-mode).
 *  - $isDeclarative: the run is a single meta-flow.
 *  - $expandedFlows: the resolved list of normal flow names after meta-flow
 *                    expansion. Null in single-flow runs (matches the
 *                    pre-refactor header behaviour).
 */
class FlowsPreparation
{
    public bool $success;

    /** @var string[] */
    public array $errors;

    public ?FlowPlan $plan;

    public ?EffectiveOptionsResolution $resolution;

    public ?ConfigurationResult $config;

    public bool $isSingleFlow;

    public bool $isDeclarative;

    /** @var string[]|null */
    public ?array $expandedFlows;

    /**
     * @param string[] $errors
     * @param string[]|null $expandedFlows
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Aggregates the run-context flags too.
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Internal factory; callers use ::failure / ::success.
     */
    private function __construct(
        bool $success,
        array $errors,
        ?FlowPlan $plan,
        ?EffectiveOptionsResolution $resolution,
        ?ConfigurationResult $config,
        bool $isSingleFlow,
        bool $isDeclarative,
        ?array $expandedFlows
    ) {
        $this->success = $success;
        $this->errors = $errors;
        $this->plan = $plan;
        $this->resolution = $resolution;
        $this->config = $config;
        $this->isSingleFlow = $isSingleFlow;
        $this->isDeclarative = $isDeclarative;
        $this->expandedFlows = $expandedFlows;
    }

    /**
     * @param string[] $errors
     */
    public static function failure(array $errors): self
    {
        return new self(false, $errors, null, null, null, false, false, null);
    }

    /**
     * @param string[]|null $expandedFlows
     */
    public static function success(
        FlowPlan $plan,
        EffectiveOptionsResolution $resolution,
        ConfigurationResult $config,
        bool $isSingleFlow,
        bool $isDeclarative,
        ?array $expandedFlows
    ): self {
        return new self(true, [], $plan, $resolution, $config, $isSingleFlow, $isDeclarative, $expandedFlows);
    }
}
