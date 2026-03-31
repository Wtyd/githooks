<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Configuration;

use InvalidArgumentException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Wtyd\GitHooks\ConfigurationFile\Exception\ConfigurationFileNotFoundException;
use Wtyd\GitHooks\ConfigurationFile\Exception\ParseConfigurationFileException;
use Wtyd\GitHooks\Registry\ToolRegistry;

class ConfigurationParser
{
    private string $rootPath;

    private ToolRegistry $toolRegistry;

    public function __construct(ToolRegistry $toolRegistry)
    {
        $this->rootPath = getcwd() ?: '';
        $this->toolRegistry = $toolRegistry;
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
            $result->addWarning(
                'Legacy configuration format detected (Options/Tools). '
                . "Use 'githooks conf:init' to generate the new hooks/flows/jobs format."
            );
            return ConfigurationResult::legacy($raw, $filePath, $result);
        }

        return $this->parseV3($raw, $filePath);
    }

    public function isLegacyFormat(array $config): bool
    {
        $hasLegacyKeys = array_key_exists('Options', $config) || array_key_exists('Tools', $config);
        $hasV3Keys = array_key_exists('hooks', $config)
            || array_key_exists('flows', $config)
            || array_key_exists('jobs', $config);

        return $hasLegacyKeys && !$hasV3Keys;
    }

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
        $jobs = [];
        if (empty($jobsRaw)) {
            $result->addError("The 'jobs' section is missing or empty.");
        } else {
            foreach ($jobsRaw as $jobName => $jobData) {
                if (!is_array($jobData)) {
                    $result->addError("Job '$jobName' must be an array.");
                    continue;
                }
                $job = JobConfiguration::fromArray($jobName, $jobData, $this->toolRegistry, $result);
                if ($job !== null) {
                    $jobs[$jobName] = $job;
                }
            }
        }

        $availableJobNames = array_keys($jobs);

        // 3. Parse flows (reference jobs)
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

        $availableFlowNames = array_keys($flows);

        // 4. Parse hooks (reference flows and jobs)
        $hooks = null;
        $hooksRaw = $raw['hooks'] ?? [];
        if (!empty($hooksRaw)) {
            $hooks = HookConfiguration::fromArray($hooksRaw, $availableFlowNames, $availableJobNames, $result);
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
     * @throws ConfigurationFileNotFoundException
     * @throws ParseConfigurationFileException
     */
    protected function readFile(string $filePath): array
    {
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
}
