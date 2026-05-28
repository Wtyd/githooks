<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

use Wtyd\GitHooks\Configuration\ConfigurationResult;

/**
 * Result of {@see JobRunner::prepare()}: either a ready-to-execute plan
 * (`success=true`, all fields populated) or a list of stderr-ready error
 * messages (`success=false`, plan/resolution/config null).
 *
 * The Command consumes this and decides how to render — emit the errors,
 * configure the executor, emit the conditions header, execute the plan,
 * render the result. The Runner deliberately never touches I/O.
 */
class JobPreparation
{
    public bool $success;

    /** @var string[] mensajes a emitir por stderr antes de devolver exit */
    public array $errors;

    public ?FlowPlan $plan;

    public ?EffectiveOptionsResolution $resolution;

    public ?ConfigurationResult $config;

    /**
     * @param string[] $errors
     */
    private function __construct(
        bool $success,
        array $errors,
        ?FlowPlan $plan,
        ?EffectiveOptionsResolution $resolution,
        ?ConfigurationResult $config
    ) {
        $this->success = $success;
        $this->errors = $errors;
        $this->plan = $plan;
        $this->resolution = $resolution;
        $this->config = $config;
    }

    /**
     * @param string[] $errors
     */
    public static function failure(array $errors): self
    {
        return new self(false, $errors, null, null, null);
    }

    public static function success(
        FlowPlan $plan,
        EffectiveOptionsResolution $resolution,
        ConfigurationResult $config
    ): self {
        return new self(true, [], $plan, $resolution, $config);
    }
}
