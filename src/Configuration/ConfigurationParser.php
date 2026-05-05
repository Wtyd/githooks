<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Configuration;

use InvalidArgumentException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Wtyd\GitHooks\Configuration\KeySuggestion;
use Wtyd\GitHooks\ConfigurationFile\Exception\ConfigurationFileNotFoundException;
use Wtyd\GitHooks\ConfigurationFile\Exception\ParseConfigurationFileException;
use Wtyd\GitHooks\Jobs\JobRegistry;
use Wtyd\GitHooks\Registry\ToolRegistry;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Parser orchestrates all configuration types
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Parser handles legacy, v3, inheritance, and validation
 */
class ConfigurationParser
{
    private string $rootPath;

    private ToolRegistry $toolRegistry;

    private JobRegistry $jobRegistry;

    public function __construct(ToolRegistry $toolRegistry, string $rootPath = '', ?JobRegistry $jobRegistry = null)
    {
        $this->rootPath = $rootPath !== '' ? $rootPath : (getcwd() ?: '');
        $this->toolRegistry = $toolRegistry;
        $this->jobRegistry = $jobRegistry ?? new JobRegistry();
    }

    /**
     * Parse a configuration file and return a ConfigurationResult.
     *
     * @throws ConfigurationFileNotFoundException
     * @throws ParseConfigurationFileException
     */
    public function parse(string $configFile = ''): ConfigurationResult
    {
        $filePath = $this->resolveFilePath($configFile);
        $raw = $this->readFile($filePath);
        [$raw, $localFilePath] = $this->mergeLocalOverride($raw, $filePath);

        if ($this->isLegacyFormat($raw)) {
            $result = new ValidationResult();
            $this->addYamlDeprecationWarning($filePath, $result);
            $result->addWarning(
                'Legacy configuration format detected (Options/Tools). '
                . "Use 'githooks conf:init' to generate the new hooks/flows/jobs format."
            );
            $configResult = ConfigurationResult::legacy($raw, $filePath, $result);
            $configResult->setLocalFilePath($localFilePath);
            return $configResult;
        }

        $configResult = $this->parseV3($raw, $filePath);
        $configResult->setLocalFilePath($localFilePath);
        return $configResult;
    }

    /** @param array<string, mixed> $config */
    public function isLegacyFormat(array $config): bool
    {
        $hasLegacyKeys = array_key_exists('Options', $config) || array_key_exists('Tools', $config);
        $hasV3Keys = array_key_exists('hooks', $config)
            || array_key_exists('flows', $config)
            || array_key_exists('jobs', $config);

        return $hasLegacyKeys && !$hasV3Keys;
    }

    /** @param array<string, mixed> $raw */
    protected function parseV3(array $raw, string $filePath): ConfigurationResult
    {
        $result = new ValidationResult();

        $this->addYamlDeprecationWarning($filePath, $result);

        // 1. Parse global flow-level options
        $globalOptions = null;
        $flowsRaw = $raw['flows'] ?? [];
        if (array_key_exists('options', $flowsRaw) && is_array($flowsRaw['options'])) {
            $globalOptions = OptionsConfiguration::fromArray($flowsRaw['options'], $result);
            unset($flowsRaw['options']);
        } else {
            $globalOptions = new OptionsConfiguration();
        }

        // 2. Parse jobs (standalone, no cross-references)
        $jobsRaw = $raw['jobs'] ?? [];
        $jobs = $this->parseJobs($jobsRaw, $result);
        // Use all declared job names (including ones that failed validation) so that
        // flows don't emit confusing "undefined job" warnings for jobs with type errors.
        $declaredJobNames = is_array($jobsRaw) ? array_keys($jobsRaw) : [];

        // 2b. Validate flat namespace between jobs and flows (CHK-002, CON-009).
        $declaredFlowNames = array_keys($flowsRaw);
        $collisions = array_intersect($declaredJobNames, $declaredFlowNames);
        foreach ($collisions as $clashing) {
            $result->addError("name '$clashing' is declared as both job and flow.");
        }

        // 3. Parse flows (reference jobs)
        $flows = $this->parseFlows($flowsRaw, $declaredJobNames, $result);
        $availableFlowNames = array_keys($flows);

        // 4. Parse hooks (reference flows and jobs)
        $hooks = null;
        $hooksRaw = $raw['hooks'] ?? [];
        if (!empty($hooksRaw)) {
            $hooks = HookConfiguration::fromArray($hooksRaw, $availableFlowNames, $declaredJobNames, $result);
        }

        // 4b. Cross-validation: a job's 'memory' reservation cannot exceed any
        // memory-budget.warn-above (or fail-above when warn is absent) it would
        // be admitted against — global or per-flow. Such a job could never run.
        $this->validateMemoryReserves($jobs, $globalOptions, $flows, $result);

        // 4c. Cross-validation: uncontrollable jobs (phpstan, custom) where the
        // declared cores/neon-workers exceed the flow's `processes` budget. The
        // flow rules everywhere else (clamp at applyThreadLimit), but for these
        // two GitHooks cannot force the tool to honour the budget — so we warn
        // so the operator knows other jobs in the flow will wait in serial.
        $this->validateCoresAgainstFlowBudgets($jobs, $globalOptions, $flows, $result);

        // 5. Warn about unknown top-level keys
        $knownKeys = ['hooks', 'flows', 'jobs'];
        foreach (array_keys($raw) as $key) {
            if (!in_array($key, $knownKeys, true)) {
                $suggestion = KeySuggestion::suggestionFor((string) $key, $knownKeys);
                $result->addWarning("Unknown top-level key '$key'. It will be ignored.{$suggestion}");
            }
        }

        return new ConfigurationResult(
            $filePath,
            $globalOptions,
            $jobs,
            $flows,
            $hooks,
            $result
        );
    }

    /**
     * Cross-validation of `memory` reservations against `memory-budget` ceilings.
     * For each job that declares a short-form `memory: <int>`, fail if it
     * exceeds the global memory-budget reference, or any per-flow memory-budget
     * reference of a flow that includes the job.
     *
     * @param array<string, JobConfiguration> $jobs
     * @param array<string, FlowConfiguration> $flows
     */
    private function validateMemoryReserves(
        array $jobs,
        OptionsConfiguration $globalOptions,
        array $flows,
        ValidationResult $result
    ): void {
        foreach ($jobs as $name => $job) {
            $reserve = $job->getMemoryReserve();
            if ($reserve === null) {
                continue;
            }

            $this->validateReserveAgainstGlobalBudget($name, $reserve, $globalOptions, $result);
            $this->validateReserveAgainstFlowBudgets($name, $reserve, $flows, $result);
        }
    }

    private function validateReserveAgainstGlobalBudget(
        string $jobName,
        int $reserve,
        OptionsConfiguration $globalOptions,
        ValidationResult $result
    ): void {
        $globalBudget = $globalOptions->getMemoryBudget();
        if ($globalBudget === null) {
            return;
        }
        $ref = $globalBudget->getBinPackingReference();
        if ($ref !== null && $reserve > $ref) {
            $result->addError(
                "Job '$jobName': 'memory' ($reserve) exceeds memory-budget ($ref) — could never run."
            );
        }
    }

    /**
     * @param array<string, FlowConfiguration> $flows
     */
    private function validateReserveAgainstFlowBudgets(
        string $jobName,
        int $reserve,
        array $flows,
        ValidationResult $result
    ): void {
        foreach ($flows as $flowName => $flow) {
            if (!in_array($jobName, $flow->getJobs(), true)) {
                continue;
            }
            $flowOptions = $flow->getOptions();
            $flowBudget = $flowOptions !== null ? $flowOptions->getMemoryBudget() : null;
            if ($flowBudget === null) {
                continue;
            }
            $ref = $flowBudget->getBinPackingReference();
            if ($ref !== null && $reserve > $ref) {
                $result->addError(
                    "Job '$jobName': 'memory' ($reserve) exceeds memory-budget ($ref) "
                    . "declared in flow '$flowName' — could never run."
                );
            }
        }
    }

    /**
     * Cross-validation of cores reservations against each flow's `processes`
     * budget for uncontrollable jobs (phpstan reads .neon, custom is opaque).
     * In both cases GitHooks cannot force the tool to honour the budget at
     * runtime, so we surface the mismatch as a warning so the operator
     * knows other jobs in the flow will wait in serial while the offending
     * job runs. Controllable tools are not validated here — they are
     * clamped at applyThreadLimit by FlowExecutor.
     *
     * @param array<string, JobConfiguration> $jobs
     * @param array<string, FlowConfiguration> $flows
     */
    private function validateCoresAgainstFlowBudgets(
        array $jobs,
        OptionsConfiguration $globalOptions,
        array $flows,
        ValidationResult $result
    ): void {
        foreach ($jobs as $name => $job) {
            $declared = $this->declaredCoresForUncontrollable($job);
            if ($declared === null) {
                continue;
            }
            [$value, $source] = $declared;
            $this->validateCoresAgainstFlows($name, $value, $source, $globalOptions, $flows, $result);
        }
    }

    /**
     * Return [value, source] when the job is uncontrollable and declares
     * a value to compare against `processes`, or null otherwise. `source`
     * is a human-readable label embedded in the warning so the operator
     * knows where the value came from.
     *
     * @return array{0:int,1:string}|null
     */
    private function declaredCoresForUncontrollable(JobConfiguration $job): ?array
    {
        $type = $job->getType();
        if ($type === 'custom') {
            $cores = $job->getCores();
            return $cores !== null ? [$cores, "cores=$cores"] : null;
        }
        if ($type === 'phpstan') {
            try {
                $instance = $this->jobRegistry->create($job);
            } catch (\Throwable $e) {
                return null;
            }
            if (!$instance instanceof \Wtyd\GitHooks\Jobs\PhpstanJob) {
                return null;
            }
            $workers = $instance->getDeclaredNeonWorkers();
            return [$workers, "neon-workers=$workers"];
        }
        return null;
    }

    /**
     * Compare the declared cores against every flow that runs the job. Emits
     * one warning per flow exceeded; flows that fit are silent.
     *
     * @param array<string, FlowConfiguration> $flows
     */
    private function validateCoresAgainstFlows(
        string $jobName,
        int $declared,
        string $source,
        OptionsConfiguration $globalOptions,
        array $flows,
        ValidationResult $result
    ): void {
        foreach ($flows as $flowName => $flow) {
            if (!in_array($jobName, $flow->getJobs(), true)) {
                continue;
            }
            $flowOptions = $flow->getOptions();
            $processes = $flowOptions !== null
                ? $flowOptions->getProcesses()
                : $globalOptions->getProcesses();
            if ($declared <= $processes) {
                continue;
            }
            $result->addWarning(
                "Job '$jobName' in flow '$flowName': $source exceeds the flow's "
                . "processes ($processes). The job will saturate the budget while "
                . "running; other jobs in the flow will wait in serial. Adjust "
                . "either side or accept this trade-off."
            );
        }
    }

    /**
     * @param array<string, mixed> $jobsRaw
     * @return array<string, JobConfiguration>
     */
    private function parseJobs(array $jobsRaw, ValidationResult $result): array
    {
        $jobs = [];
        if (empty($jobsRaw)) {
            $result->addError("The 'jobs' section is missing or empty.");
            return $jobs;
        }

        $jobsRaw = $this->resolveJobInheritance($jobsRaw, $result);

        foreach ($jobsRaw as $jobName => $jobData) {
            if (!is_array($jobData)) {
                $result->addError("Job '$jobName' must be an array.");
                continue;
            }
            $job = JobConfiguration::fromArray($jobName, $jobData, $this->toolRegistry, $result, $this->jobRegistry);
            if ($job !== null) {
                $jobs[$jobName] = $job;
            }
        }
        return $jobs;
    }

    /**
     * Resolve `extends` references in jobs before parsing.
     * Each child inherits all keys from its parent, with child keys overriding.
     *
     * @param array<string, mixed> $jobsRaw
     * @return array<string, mixed>
     */
    private function resolveJobInheritance(array $jobsRaw, ValidationResult $result): array
    {
        /** @var array<string, array<string, mixed>> */
        $resolved = [];
        /** @var array<string, bool> */
        $resolving = [];

        foreach (array_keys($jobsRaw) as $name) {
            $this->resolveOneJob((string) $name, $jobsRaw, $resolved, $resolving, $result);
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $all
     * @param array<string, array<string, mixed>> &$resolved
     * @param-out array<string, array<string, mixed>> $resolved
     * @param array<string, bool> &$resolving
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Validates parent exists, no self-ref, no cycles, type
     */
    private function resolveOneJob(
        string $name,
        array $all,
        array &$resolved,
        array &$resolving,
        ValidationResult $result
    ): void {
        if (isset($resolved[$name]) || !isset($all[$name])) {
            return;
        }

        $data = $all[$name];
        if (!is_array($data)) {
            // Non-array jobs are skipped here; parseJobs() will report the error
            return;
        }

        if (isset($resolving[$name])) {
            $result->addError("Circular 'extends' detected in job '$name'.");
            return;
        }

        $resolving[$name] = true;

        if (isset($data['extends'])) {
            $parent = $data['extends'];

            if (!is_string($parent)) {
                $result->addError("Job '$name': 'extends' must be a string.");
            } elseif ($parent === $name) {
                $result->addError("Job '$name' cannot extend itself.");
            } elseif (!isset($all[$parent])) {
                $result->addError("Job '$name' extends '$parent' which is not defined.");
            } else {
                $this->resolveOneJob($parent, $all, $resolved, $resolving, $result);
                if (isset($resolved[$parent])) {
                    $data = array_merge($resolved[$parent], $data);
                }
            }
            unset($data['extends']);
        }

        /** @var array<string, mixed> $data */
        $resolved[$name] = $data;
        unset($resolving[$name]);
    }

    /**
     * @param array<string, mixed> $flowsRaw
     * @param string[] $availableJobNames
     * @return array<string, FlowConfiguration>
     */
    private function parseFlows(array $flowsRaw, array $availableJobNames, ValidationResult $result): array
    {
        $flows = [];
        foreach ($flowsRaw as $flowName => $flowData) {
            if (!is_array($flowData)) {
                $result->addError("Flow '$flowName' must be an array.");
                continue;
            }
            $flow = FlowConfiguration::fromArray($flowName, $flowData, $availableJobNames, $result);
            if ($flow !== null) {
                $flows[$flowName] = $flow;
            }
        }

        // Second pass: validate meta-flow references against the parsed flow set
        // (existence, no nesting CON-008, empty/single warnings CHK-003/004).
        $this->validateMetaFlowReferences($flows, $result);

        return $flows;
    }

    /**
     * @param array<string, FlowConfiguration> $flows
     */
    private function validateMetaFlowReferences(array $flows, ValidationResult $result): void
    {
        foreach ($flows as $flow) {
            if (!$flow->isMetaFlow()) {
                continue;
            }

            $references = $flow->getFlowReferences();
            $name = $flow->getName();

            if ($references === []) {
                $result->addWarning("meta-flow '$name' has no flows declared.");
                continue;
            }

            if (count($references) === 1) {
                $result->addWarning(
                    "meta-flow '$name' contains a single flow; "
                    . "consider declaring options on the flow itself."
                );
            }

            foreach ($references as $referenced) {
                if (!isset($flows[$referenced])) {
                    $result->addError("meta-flow '$name' references unknown flow '$referenced'.");
                    continue;
                }

                if ($flows[$referenced]->isMetaFlow()) {
                    $result->addError(
                        "meta-flow '$name' references '$referenced' which is also a meta-flow; "
                        . "nesting is not supported in v3.3."
                    );
                }
            }
        }
    }

    /**
     * @throws ConfigurationFileNotFoundException
     * @throws ParseConfigurationFileException
     * @return array<string, mixed>
     */
    protected function readFile(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new ConfigurationFileNotFoundException(
                "Configuration file not found: $filePath"
            );
        }

        $extension = pathinfo($filePath, PATHINFO_EXTENSION);

        try {
            if ($extension === 'yml' || $extension === 'yaml') {
                return Yaml::parseFile($filePath);
            }

            if ($extension === 'php') {
                $config = require $filePath;
                if (!is_array($config)) {
                    throw new ParseConfigurationFileException('PHP configuration file does not return an array.');
                }
                return $config;
            }
        } catch (ParseException $e) {
            throw ParseConfigurationFileException::forMessage($e->getMessage());
        }

        throw new InvalidArgumentException("Unsupported config file type: .$extension");
    }

    protected function resolveFilePath(string $configFile): string
    {
        if (!empty($configFile)) {
            if ($configFile[0] === '/' || preg_match('/^[a-zA-Z]:[\\\\\/]/', $configFile)) {
                return $configFile;
            }
            return $this->rootPath . DIRECTORY_SEPARATOR . $configFile;
        }

        return $this->findConfigurationFile();
    }

    /**
     * @throws ConfigurationFileNotFoundException
     */
    protected function findConfigurationFile(): string
    {
        $possiblePaths = [
            "$this->rootPath/githooks.php",
            "$this->rootPath/qa/githooks.php",
            "$this->rootPath/githooks.yml",
            "$this->rootPath/qa/githooks.yml",
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        throw new ConfigurationFileNotFoundException();
    }

    /**
     * Look for a .local.php sibling of the main config file and merge it.
     *
     * @param array<string, mixed> $raw Main config array
     * @return array{0: array<string, mixed>, 1: string|null} Merged config and local file path
     */
    private function mergeLocalOverride(array $raw, string $filePath): array
    {
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        if ($extension !== 'php') {
            return [$raw, null];
        }

        $localPath = substr($filePath, 0, -4) . '.local.php';
        if (!file_exists($localPath)) {
            return [$raw, null];
        }

        $localRaw = $this->readFile($localPath);

        /** @var array<string, mixed> */
        $merged = array_replace_recursive($raw, $localRaw);

        return [$merged, $localPath];
    }

    private function addYamlDeprecationWarning(string $filePath, ValidationResult $result): void
    {
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        if ($extension === 'yml' || $extension === 'yaml') {
            $result->addWarning(
                'YAML configuration files are deprecated since v3.0 and will be removed in v4.0. '
                . 'Use PHP format (githooks.php) instead. Run "githooks conf:init" to generate.'
            );
        }
    }
}
