<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Configuration;

use Wtyd\GitHooks\Hooks;

class FlowConfiguration
{
    private string $name;

    /** @var string[] Job names referenced by this flow */
    private array $jobs;

    private ?OptionsConfiguration $options;

    /**
     * @param string[] $jobs
     */
    public function __construct(string $name, array $jobs, ?OptionsConfiguration $options = null)
    {
        $this->name = $name;
        $this->jobs = $jobs;
        $this->options = $options;
    }

    /**
     * Build from raw config entry.
     *
     * @param string $name Flow name (the key in 'flows' section)
     * @param array  $raw  The flow definition array
     * @param string[] $availableJobNames All job names defined in the 'jobs' section
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
                $result->addError("Flow '$name' references undefined job '$jobRef'.");
            }
        }

        $options = null;
        if (array_key_exists('options', $raw) && is_array($raw['options'])) {
            $options = OptionsConfiguration::fromArray($raw['options'], $result);
        }

        return new self($name, $raw['jobs'], $options);
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
}
