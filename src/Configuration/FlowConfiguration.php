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

    /** @var JobRef[] Rich job refs (FEAT-1); parallel to $jobs, in declaration order */
    private array $jobRefs;

    /** @var string[]|null Flow names referenced by this meta-flow (null for normal flows) */
    private ?array $flowReferences;

    private ?OptionsConfiguration $options;

    private ?string $execution;

    /** @var FlowOnRule[]|null FEAT-2: ordered rules `branch_pattern => attrs` */
    private ?array $onRules;

    /** FEAT-3: dependency graph among job entries, or null when no `needs` declared */
    private ?FlowDependencyGraph $dependencyGraph;

    /**
     * @param string[] $jobs
     * @param string[]|null $flowReferences null for normal flows; array (possibly empty) for meta-flows
     * @param JobRef[]|null $jobRefs FEAT-1 rich refs. Defaults to one JobRef::fromString() per name in $jobs
     * @param FlowOnRule[]|null $onRules FEAT-2 branch-based mode rules in declaration order
     * @param FlowDependencyGraph|null $dependencyGraph FEAT-3 DAG of `needs` relations
     */
    public function __construct(
        string $name,
        array $jobs,
        ?OptionsConfiguration $options = null,
        ?string $execution = null,
        ?array $flowReferences = null,
        ?array $jobRefs = null,
        ?array $onRules = null,
        ?FlowDependencyGraph $dependencyGraph = null
    ) {
        $this->name = $name;
        $this->jobs = $jobs;
        $this->jobRefs = $jobRefs ?? array_map([JobRef::class, 'fromString'], $jobs);
        $this->flowReferences = $flowReferences;
        $this->options = $options;
        $this->execution = $execution;
        $this->onRules = $onRules;
        $this->dependencyGraph = $dependencyGraph;
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
        $jobRefs = null;
        $flowReferences = null;

        if ($hasJobs) {
            if (!is_array($raw['jobs']) || empty($raw['jobs'])) {
                $result->addError("Flow '$name' must have a non-empty 'jobs' array.");
                return null;
            }
            $parsed = self::parseJobEntries($name, $raw['jobs'], $availableJobNames, $result);
            if ($parsed === null) {
                return null;
            }
            [$jobs, $jobRefs] = $parsed;
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

        $onRules = self::parseOn($name, $raw, $result);
        if ($onRules === false) {
            return null;
        }

        // FEAT-3: build dependency graph only for normal flows that declared
        // jobs. Meta-flows have no jobs of their own — their composition is
        // resolved at expansion time. ($jobRefs is guaranteed non-null on
        // the $hasJobs branch by parseJobEntries — checked above.)
        $dependencyGraph = null;
        if ($hasJobs) {
            // $jobRefs is non-null on the $hasJobs branch (set by parseJobEntries
            // above; otherwise we returned). PHPStan tracks the flow narrowing.
            $dependencyGraph = FlowDependencyGraph::build($name, $jobRefs, $result);
            if ($dependencyGraph === null) {
                return null;
            }
        }

        return new self($name, $jobs, $options, $execution, $flowReferences, $jobRefs, $onRules, $dependencyGraph);
    }

    /**
     * Parse the optional `on` map (FEAT-2). Returns:
     *   - false: validation error, caller aborts
     *   - null: `on` absent / empty (no admission rules)
     *   - FlowOnRule[]: declaration-ordered rules
     *
     * @param array<string, mixed> $raw
     * @return FlowOnRule[]|null|false
     */
    private static function parseOn(string $flowName, array $raw, ValidationResult $result)
    {
        if (!array_key_exists('on', $raw) || $raw['on'] === null) {
            // `null` is the sentinel for "cancel inherited `on` from the shared
            // config" — consistent with FEAT-1's only-files/exclude-files
            // semantics. Absent or null both mean "no admission rules".
            return null;
        }
        $rawOn = $raw['on'];
        if (!is_array($rawOn)) {
            $result->addError("Flow '$flowName': 'on' must be an array of branch patterns.");
            return false;
        }
        if ($rawOn === []) {
            $result->addWarning("Flow '$flowName': 'on' is declared but empty; it will be ignored.");
            return null;
        }

        $rules = [];
        $hasCatchAll = false;
        foreach ($rawOn as $pattern => $rawAttrs) {
            $rule = FlowOnRule::fromArray((string) $pattern, $rawAttrs, $result, $flowName);
            if ($rule === null) {
                return false;
            }
            if ($rule->getPattern() === '*') {
                $hasCatchAll = true;
            }
            $rules[] = $rule;
        }

        if (!$hasCatchAll) {
            $result->addWarning(
                "Flow '$flowName': 'on' has no catch-all '*' pattern; non-matching branches fall back to "
                . "flow.execution / flows.options.execution / default."
            );
        }

        return $rules;
    }

    /**
     * Parse the `jobs` list: each entry is either a string (plain name) or an
     * object `{job: string, only-files?: ..., exclude-files?: ...}`. Returns a
     * tuple `[names, refs]` parallel-ordered, or null if any entry fails hard.
     *
     * @param mixed[] $rawEntries
     * @param string[] $availableJobNames
     * @return array{0: string[], 1: JobRef[]}|null
     */
    private static function parseJobEntries(
        string $flowName,
        array $rawEntries,
        array $availableJobNames,
        ValidationResult $result
    ): ?array {
        $names = [];
        $refs = [];
        foreach ($rawEntries as $entry) {
            $ref = self::buildJobRef($flowName, $entry, $result);
            if ($ref === null) {
                return null;
            }
            if (!in_array($ref->getTarget(), $availableJobNames, true)) {
                $result->addWarning(
                    "Flow '$flowName' references undefined job '" . $ref->getTarget() . "'. It will be skipped."
                );
            }
            $names[] = $ref->getTarget();
            $refs[] = $ref;
        }
        return [$names, $refs];
    }

    /**
     * @param mixed $entry raw value from `jobs[]` — string, array, or other
     */
    private static function buildJobRef(string $flowName, $entry, ValidationResult $result): ?JobRef
    {
        if (is_string($entry)) {
            return JobRef::fromString($entry);
        }
        if (is_array($entry)) {
            return JobRef::fromArray($entry, $result, $flowName);
        }
        $result->addError(
            "Flow '$flowName': job entry must be a string or an object with a 'job' key."
        );
        return null;
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

    /** @return JobRef[] */
    public function getJobReferences(): array
    {
        return $this->jobRefs;
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

    /**
     * FEAT-2: ordered list of branch → attrs rules, or null if not declared.
     *
     * @return FlowOnRule[]|null
     */
    public function getOn(): ?array
    {
        return $this->onRules;
    }

    /**
     * FEAT-3: DAG of `needs` relations among job entries. Null for meta-flows
     * (no jobs of their own) or for normal flows constructed without going
     * through `fromArray()` (e.g. internal aggregates).
     */
    public function getDependencyGraph(): ?FlowDependencyGraph
    {
        return $this->dependencyGraph;
    }
}
