<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

use Wtyd\GitHooks\Configuration\OptionsConfiguration;

/**
 * Immutable result of EffectiveOptionsResolver: the resolved OptionsConfiguration,
 * the execution mode, and a per-key trace mapping each option to its source layer
 * (cli, flows.<X>.options, flows.<alias>.options, flows.options, default).
 */
final class EffectiveOptionsResolution
{
    private OptionsConfiguration $options;

    private string $executionMode;

    /** @var array<string, array{value: mixed, source: string}> */
    private array $trace;

    /**
     * @param array<string, array{value: mixed, source: string}> $trace
     */
    public function __construct(OptionsConfiguration $options, string $executionMode, array $trace)
    {
        $this->options = $options;
        $this->executionMode = $executionMode;
        $this->trace = $trace;
    }

    public function getOptions(): OptionsConfiguration
    {
        return $this->options;
    }

    public function getExecutionMode(): string
    {
        return $this->executionMode;
    }

    /**
     * @return array<string, array{value: mixed, source: string}>
     */
    public function getTrace(): array
    {
        return $this->trace;
    }
}
