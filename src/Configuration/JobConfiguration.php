<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Configuration;

use Wtyd\GitHooks\Execution\ExecutionMode;
use Wtyd\GitHooks\Jobs\JobRegistry;
use Wtyd\GitHooks\Registry\ToolRegistry;

/** @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Validation of type, execution mode, arguments, and common keys */
class JobConfiguration
{
    private string $name;

    private string $type;

    /** @var array<string, mixed> Tool-specific arguments (everything except 'type') */
    private array $config;

    /** @param array<string, mixed> $config */
    public function __construct(string $name, string $type, array $config)
    {
        $this->name = $name;
        $this->type = $type;
        $this->config = $config;
    }

    /**
     * Build from raw config entry. Validates type against the registry.
     *
     * @param string $name Job name (the key in 'jobs' section)
     * @param array<string, mixed> $raw The job definition array
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Validates type, execution mode, custom keys, and tool arguments
     * @SuppressWarnings(PHPMD.NPathComplexity) Each validation check adds an independent early-return branch
     */
    public static function fromArray(
        string $name,
        array $raw,
        ToolRegistry $toolRegistry,
        ValidationResult $result,
        ?JobRegistry $jobRegistry = null
    ): ?self {
        if (!array_key_exists('type', $raw)) {
            $result->addError("Job '$name' is missing the required 'type' key.");
            return null;
        }

        $type = $raw['type'];

        if (!is_string($type)) {
            $result->addError("Job '$name': 'type' must be a string.");
            return null;
        }

        if ($type !== 'custom' && !$toolRegistry->isSupported($type)) {
            $result->addError("Job '$name': type '$type' is not a supported tool.");
            return null;
        }

        if ($type === 'custom' && !array_key_exists('script', $raw) && !array_key_exists('executablePath', $raw)) {
            $result->addError("Job '$name': custom jobs require a 'script' or 'executablePath' key.");
            return null;
        }

        $config = $raw;
        unset($config['type']);

        if (array_key_exists('execution', $config)) {
            if (!is_string($config['execution']) || !ExecutionMode::isValid($config['execution'])) {
                $result->addError("Job '$name': 'execution' must be one of: " . implode(', ', ExecutionMode::ALL) . ".");
                return null;
            }
        }

        if ($type === 'custom') {
            self::validateCustomJobKeys($name, $config, $result);
        } elseif ($jobRegistry !== null) {
            self::validateArguments($name, $type, $config, $jobRegistry, $result);
        }

        return new self($name, $type, $config);
    }

    /**
     * Validate job arguments against the ARGUMENT_MAP of the target job class.
     *
     * @param array<string, mixed> $config
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Switch covers all ARGUMENT_MAP types
     */
    private static function validateArguments(
        string $name,
        string $type,
        array $config,
        JobRegistry $jobRegistry,
        ValidationResult $result
    ): void {
        $argumentMap = $jobRegistry->getArgumentMap($type);
        if (empty($argumentMap)) {
            return;
        }

        $knownKeys = array_merge(
            array_keys($argumentMap),
            ['executablePath', 'otherArguments', 'ignoreErrorsOnExit', 'failFast', 'paths', 'rules', 'script', 'accelerable', 'execution']
        );

        foreach ($config as $key => $value) {
            if (!in_array($key, $knownKeys, true)) {
                $result->addWarning("Job '$name': unknown key '$key' for type '$type'.");
            }
        }

        foreach ($argumentMap as $key => $spec) {
            if (!array_key_exists($key, $config)) {
                continue;
            }

            $value = $config[$key];
            $argType = $spec['type'] ?? 'value';

            switch ($argType) {
                case 'boolean':
                    if (!is_bool($value) && !is_int($value)) {
                        $result->addWarning("Job '$name': key '$key' expects a boolean value.");
                    }
                    break;
                case 'paths':
                case 'repeat':
                    if (!is_array($value)) {
                        $result->addWarning("Job '$name': key '$key' expects an array.");
                    }
                    break;
                case 'csv':
                    if (!is_array($value) && !is_string($value)) {
                        $result->addWarning("Job '$name': key '$key' expects an array or string.");
                    }
                    break;
                case 'value':
                case 'key_value':
                    if (!is_string($value) && !is_int($value)) {
                        $result->addWarning("Job '$name': key '$key' expects a string or integer.");
                    }
                    break;
            }
        }

        // Validate common keys not in ARGUMENT_MAP but used by the system
        self::validateCommonKeys($name, $config, $argumentMap, $result);
    }

    /**
     * Validate common config keys (paths, rules) that may not be in the ARGUMENT_MAP
     * but are handled directly by the job's buildCommand().
     *
     * @param array<string, mixed> $config
     * @param array<string, array<string, string>> $argumentMap
     */
    private static function validateCommonKeys(
        string $name,
        array $config,
        array $argumentMap,
        ValidationResult $result
    ): void {
        // Only validate if the key exists in config but NOT already in the ARGUMENT_MAP
        if (array_key_exists('paths', $config) && !array_key_exists('paths', $argumentMap)) {
            if (!is_array($config['paths'])) {
                $result->addWarning("Job '$name': key 'paths' expects an array.");
            }
        }

        if (array_key_exists('rules', $config) && !array_key_exists('rules', $argumentMap)) {
            if (!is_string($config['rules'])) {
                $result->addWarning("Job '$name': key 'rules' expects a string.");
            }
        }
    }

    /**
     * Validate keys for custom jobs (no ARGUMENT_MAP, only known keys).
     *
     * @param array<string, mixed> $config
     */
    private static function validateCustomJobKeys(string $name, array $config, ValidationResult $result): void
    {
        $knownKeys = ['script', 'executablePath', 'otherArguments', 'ignoreErrorsOnExit', 'failFast', 'paths', 'accelerable', 'execution'];

        foreach (array_keys($config) as $key) {
            if (!in_array($key, $knownKeys, true)) {
                $result->addWarning("Job '$name': unknown key '$key' for type 'custom'.");
            }
        }
    }

    /**
     * Whether this job supports fast mode (path filtering with staged files).
     * Explicit 'accelerable' in config takes precedence; otherwise uses the type's default.
     */
    public function isAccelerable(JobRegistry $jobRegistry): bool
    {
        if (array_key_exists('accelerable', $this->config)) {
            return (bool) $this->config['accelerable'];
        }

        return $jobRegistry->isAccelerable($this->type);
    }

    /**
     * Return a new instance with the given paths (immutable).
     *
     * @param string[] $paths
     */
    public function withPaths(array $paths): self
    {
        $newConfig = $this->config;
        $newConfig['paths'] = $paths;

        return new self($this->name, $this->type, $newConfig);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /** @return array<string, mixed> */
    public function getConfig(): array
    {
        return $this->config;
    }

    /** @return string[] */
    public function getPaths(): array
    {
        return $this->config['paths'] ?? [];
    }

    public function getExecution(): ?string
    {
        return $this->config['execution'] ?? null;
    }
}
