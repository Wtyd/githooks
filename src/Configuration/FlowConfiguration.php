<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Configuration;

use Wtyd\GitHooks\Execution\ExecutionMode;
use Wtyd\GitHooks\Hooks;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Models both normal flows and meta-flows
 */
class FlowConfiguration
{
    private string $name;

    /** @var string[] Job names referenced by this flow (empty for meta-flows) */
    private array $jobs;

    /** @var string[]|null Flow names referenced by this meta-flow (null for normal flows) */
    private ?array $flowReferences;

    private ?OptionsConfiguration $options;

    private ?string $execution;

    /**
     * @param string[] $jobs
     * @param string[]|null $flowReferences null for normal flows; array (possibly empty) for meta-flows
     */
    public function __construct(
        string $name,
        array $jobs,
        ?OptionsConfiguration $options = null,
        ?string $execution = null,
        ?array $flowReferences = null
    ) {
        $this->name = $name;
        $this->jobs = $jobs;
        $this->flowReferences = $flowReferences;
        $this->options = $options;
        $this->execution = $execution;
    }

    /**
     * Build from raw config entry. A flow declares EXACTLY ONE of `jobs` (normal flow)
     * or `flows` (meta-flow). Both or neither is an error (REQ-010).
     *
     * Cross-flow validation (existence of referenced flows, no nesting) is delegated to
     * ConfigurationParser::parseFlows() in a second pass.
     *
     * @param string $name Flow name (the key in 'flows' section)
     * @param array<string, mixed>  $raw  The flow definition array
     * @param string[] $availableJobNames All job names defined in the 'jobs' section
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Validates name, xor, jobs, flows refs, options, execution
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

        $hasJobs = array_key_exists('jobs', $raw);
        $hasFlows = array_key_exists('flows', $raw);

        if ($hasJobs && $hasFlows) {
            $result->addError("Flow '$name' declares both 'jobs' and 'flows'; pick one.");
            return null;
        }

        if (!$hasJobs && !$hasFlows) {
            $result->addError("Flow '$name' has neither 'jobs' nor 'flows'.");
            return null;
        }

        $jobs = [];
        $flowReferences = null;

        if ($hasJobs) {
            if (!is_array($raw['jobs']) || empty($raw['jobs'])) {
                $result->addError("Flow '$name' must have a non-empty 'jobs' array.");
                return null;
            }
            foreach ($raw['jobs'] as $jobRef) {
                if (!in_array($jobRef, $availableJobNames, true)) {
                    $result->addWarning("Flow '$name' references undefined job '$jobRef'. It will be skipped.");
                }
            }
            $jobs = $raw['jobs'];
        } else {
            if (!is_array($raw['flows'])) {
                $result->addError("Meta-flow '$name' must have a 'flows' array.");
                return null;
            }
            foreach ($raw['flows'] as $flowRef) {
                if (!is_string($flowRef) || $flowRef === '') {
                    $result->addError("Meta-flow '$name': 'flows' must be a list of non-empty strings.");
                    return null;
                }
            }
            $flowReferences = $raw['flows'];
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

        return new self($name, $jobs, $options, $execution, $flowReferences);
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

    /** @return string[] */
    public function getFlowReferences(): array
    {
        return $this->flowReferences ?? [];
    }

    public function isMetaFlow(): bool
    {
        return $this->flowReferences !== null;
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
