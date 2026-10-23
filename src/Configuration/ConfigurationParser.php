<?php

declare(strict_types=1);

namespace Wtyd\Alfil\Configuration;

use Wtyd\Alfil\Configuration\KeySuggestion;
use Wtyd\Alfil\Configuration\Exception\ConfigurationFileNotFoundException;
use Wtyd\Alfil\Configuration\Exception\ParseConfigurationFileException;
use Wtyd\Alfil\Configuration\Exception\UnsupportedConfigurationFormatException;
use Wtyd\Alfil\Jobs\JobRegistry;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Parser orchestrates all configuration types
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Parser handles file lookup, inheritance, and validation
 */
class ConfigurationParser
{
    /** Configuration file name since 4.0. */
    public const FILE_NAME = 'alfil.php';

    /** Pre-4.0 name: still looked up, with a deprecation, until 5.0. */
    public const LEGACY_FILE_NAME = 'githooks.php';

    private string $rootPath;

    private JobRegistry $jobRegistry;

    /**
     * Notices found while locating the file and its override, before the
     * ValidationResult they belong to exists; attached to it once parsed.
     *
     * @var string[]
     */
    private array $lookupWarnings = [];

    private ?Deprecation $lookupDeprecation = null;

    public function __construct(string $rootPath = '', ?JobRegistry $jobRegistry = null)
    {
        $this->rootPath = $rootPath !== '' ? $rootPath : (getcwd() ?: '');
        $this->jobRegistry = $jobRegistry ?? new JobRegistry();
    }

    /**
     * Parse a configuration file and return a ConfigurationResult.
     *
     * @throws ConfigurationFileNotFoundException
     * @throws ParseConfigurationFileException
     * @throws UnsupportedConfigurationFormatException
     */
    public function parse(string $configFile = ''): ConfigurationResult
    {
        $filePath = $this->resolveFilePath($configFile);
        $raw = $this->readFile($filePath);

        // Before the local override merges in: a .local.php must neither hide
        // nor produce the v2 shape. A v3 main file whose override carries
        // v2-looking keys comes out as "unknown top-level key" warnings.
        if ($this->isV2Shape($raw)) {
            throw UnsupportedConfigurationFormatException::forV2Shape($filePath);
        }

        [$raw, $localFilePath] = $this->mergeLocalOverride($raw, $filePath);

        $configResult = $this->parseV3($raw, $filePath);
        $configResult->setLocalFilePath($localFilePath);

        $validation = $configResult->getValidation();
        if ($this->lookupDeprecation !== null) {
            $validation->addDeprecation($this->lookupDeprecation);
        }
        foreach ($this->lookupWarnings as $warning) {
            $validation->addWarning($warning);
        }

        return $configResult;
    }

    /**
     * The pure v2 shape (Options and/or Tools, no v3 section). Anything that
     * declares a v3 section is parsed as v3, stray keys and all.
     *
     * @param array<string, mixed> $config
     */
    private function isV2Shape(array $config): bool
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
            if (!$instance instanceof \Wtyd\Alfil\Jobs\PhpstanJob) {
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

        // Checked against the raw input: resolveJobInheritance() drops non-array
        // entries, so by the time inheritance is resolved the job is gone and the
        // malformed declaration would go unreported — leaving a job that never
        // runs inside a configuration reported as valid.
        foreach ($jobsRaw as $jobName => $jobData) {
            if (!is_array($jobData)) {
                $message = "Job '$jobName' must be an array.";
                $result->addError($message);
                $result->addRejectedJob((string) $jobName, [$message]);
            }
        }

        $jobsRaw = $this->resolveJobInheritance($jobsRaw, $result);

        foreach ($jobsRaw as $jobName => $jobData) {
            // The errors JobConfiguration adds while rejecting a job are the
            // reason it was rejected; capturing the delta keeps them tied to the
            // job so conf:check can report it instead of dropping it silently.
            $errorsBefore = count($result->getErrors());
            $job = JobConfiguration::fromArray($jobName, $jobData, $result, $this->jobRegistry);
            if ($job !== null) {
                $jobs[$jobName] = $job;
                continue;
            }
            $result->addRejectedJob((string) $jobName, array_slice($result->getErrors(), $errorsBefore));
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
     * @throws UnsupportedConfigurationFormatException
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

        // Rejected on the extension alone: the file is never read.
        if ($extension === 'yml' || $extension === 'yaml') {
            throw UnsupportedConfigurationFormatException::forYaml($filePath);
        }

        if ($extension !== 'php') {
            throw UnsupportedConfigurationFormatException::forExtension($filePath);
        }

        $config = require $filePath;
        if (!is_array($config)) {
            throw new ParseConfigurationFileException('PHP configuration file does not return an array.');
        }
        return $config;
    }

    protected function resolveFilePath(string $configFile): string
    {
        $this->lookupWarnings = [];
        $this->lookupDeprecation = null;

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
     * @throws UnsupportedConfigurationFormatException
     */
    protected function findConfigurationFile(): string
    {
        // The new name wins over the location: qa/alfil.php beats githooks.php.
        $candidates = [];
        foreach ([self::FILE_NAME, self::LEGACY_FILE_NAME] as $name) {
            foreach (['', 'qa/'] as $dir) {
                $candidates[] = "$dir$name";
            }
        }
        $existing = array_values(array_filter($candidates, function (string $relative): bool {
            return file_exists("$this->rootPath/$relative");
        }));

        if (!empty($existing)) {
            $chosen = $existing[0];
            if (basename($chosen) === self::LEGACY_FILE_NAME) {
                $this->lookupDeprecation = new Deprecation(
                    '',
                    self::LEGACY_FILE_NAME,
                    self::FILE_NAME,
                    '5.0',
                    Deprecation::KIND_CONFIG_FILE_RENAME
                );
            }
            foreach (array_slice($existing, 1) as $ignored) {
                if (basename($ignored) === self::LEGACY_FILE_NAME) {
                    $this->lookupWarnings[] = "'$ignored' is ignored: '$chosen' takes precedence. "
                        . "Remove it or merge it into '$chosen'.";
                }
            }
            return "$this->rootPath/$chosen";
        }

        // A YAML file is no longer looked up, but one left behind by 3.x gets
        // the migration message rather than "not found".
        foreach (["$this->rootPath/githooks.yml", "$this->rootPath/qa/githooks.yml"] as $path) {
            if (file_exists($path)) {
                throw UnsupportedConfigurationFormatException::forYaml($path);
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
            $this->warnAboutOrphanLocalOverride($filePath);
            return [$raw, null];
        }

        $localRaw = $this->readFile($localPath);

        /** @var array<string, mixed> */
        $merged = array_replace_recursive($raw, $localRaw);

        return [$merged, $localPath];
    }

    /**
     * The override is paired with the main file by name, so a rename of one of
     * the two leaves the other silently unused (alfil.php next to
     * githooks.local.php, or the reverse). Say so instead.
     */
    private function warnAboutOrphanLocalOverride(string $filePath): void
    {
        $names = [self::FILE_NAME => self::LEGACY_FILE_NAME, self::LEGACY_FILE_NAME => self::FILE_NAME];
        $mainName = basename($filePath);
        if (!array_key_exists($mainName, $names)) {
            return;
        }

        $otherLocal = substr($names[$mainName], 0, -4) . '.local.php';
        if (file_exists(dirname($filePath) . DIRECTORY_SEPARATOR . $otherLocal)) {
            $expected = substr($mainName, 0, -4) . '.local.php';
            $this->lookupWarnings[] = "'$otherLocal' is ignored next to '$mainName': rename it to '$expected'.";
        }
    }
}
