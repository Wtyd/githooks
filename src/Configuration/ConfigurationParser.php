<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Configuration;

use InvalidArgumentException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
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

        if ($this->isLegacyFormat($raw)) {
            $result = new ValidationResult();
            $this->addYamlDeprecationWarning($filePath, $result);
            $result->addWarning(
                'Legacy configuration format detected (Options/Tools). '
                . "Use 'githooks conf:init' to generate the new hooks/flows/jobs format."
            );
            return ConfigurationResult::legacy($raw, $filePath, $result);
        }

        return $this->parseV3($raw, $filePath);
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

        // 3. Parse flows (reference jobs)
        $flows = $this->parseFlows($flowsRaw, $declaredJobNames, $result);
        $availableFlowNames = array_keys($flows);

        // 4. Parse hooks (reference flows and jobs)
        $hooks = null;
        $hooksRaw = $raw['hooks'] ?? [];
        if (!empty($hooksRaw)) {
            $hooks = HookConfiguration::fromArray($hooksRaw, $availableFlowNames, $declaredJobNames, $result);
        }

        // 5. Warn about unknown top-level keys
        $knownKeys = ['hooks', 'flows', 'jobs'];
        foreach (array_keys($raw) as $key) {
            if (!in_array($key, $knownKeys, true)) {
                $result->addWarning("Unknown top-level key '$key'. It will be ignored.");
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
        return $flows;
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
