<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Configuration;

use Wtyd\GitHooks\Execution\ExecutionMode;
use Wtyd\GitHooks\Hooks;

class FlowConfiguration
{
    private string $name;

    /** @var string[] Job names referenced by this flow */
    private array $jobs;

    private ?OptionsConfiguration $options;

    private ?string $execution;

    /**
     * @param string[] $jobs
     */
    public function __construct(string $name, array $jobs, ?OptionsConfiguration $options = null, ?string $execution = null)
    {
        $this->name = $name;
        $this->jobs = $jobs;
        $this->options = $options;
        $this->execution = $execution;
    }

    /**
     * Build from raw config entry.
     *
     * @param string $name Flow name (the key in 'flows' section)
     * @param array<string, mixed>  $raw  The flow definition array
     * @param string[] $availableJobNames All job names defined in the 'jobs' section
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Validates name, jobs, options, and execution mode
     * @SuppressWarnings(PHPMD.NPathComplexity) Each optional config key adds an independent validation branch
     */
    public static function fromArray(
        string $name,
        array $raw,
        array $availableJobNames,
        ValidationResult $result
    ): ?self {
        if (Hooks::validate($name)) {
            $result->addError(
                "Flow '$name' cannot use a git hook event name. "
                . "Use the 'hooks' section to map events to flows."
            );
            return null;
        }

        if (!array_key_exists('jobs', $raw) || !is_array($raw['jobs']) || empty($raw['jobs'])) {
            $result->addError("Flow '$name' must have a non-empty 'jobs' array.");
            return null;
        }

        foreach ($raw['jobs'] as $jobRef) {
            if (!in_array($jobRef, $availableJobNames, true)) {
                $result->addWarning("Flow '$name' references undefined job '$jobRef'. It will be skipped.");
            }
        }

        $options = null;
        if (array_key_exists('options', $raw) && is_array($raw['options'])) {
            $options = OptionsConfiguration::fromArray($raw['options'], $result);
        }

        $execution = null;
        if (array_key_exists('execution', $raw)) {
            if (!is_string($raw['execution']) || !ExecutionMode::isValid($raw['execution'])) {
                $result->addError("Flow '$name': 'execution' must be one of: " . implode(', ', ExecutionMode::ALL) . ".");
                return null;
            }
            $execution = $raw['execution'];
        }

        return new self($name, $raw['jobs'], $options, $execution);
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** @return string[] */
    public function getJobs(): array
    {
        return $this->jobs;
    }

    public function getOptions(): ?OptionsConfiguration
    {
        return $this->options;
    }

    public function getExecution(): ?string
    {
        return $this->execution;
    }
}
