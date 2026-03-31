<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Configuration;

use Wtyd\GitHooks\Registry\ToolRegistry;

class JobConfiguration
{
    private string $name;

    private string $type;

    /** @var array<string, mixed> Tool-specific arguments (everything except 'type') */
    private array $config;

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
     * @param array $raw   The job definition array
     */
    public static function fromArray(
        string $name,
        array $raw,
        ToolRegistry $toolRegistry,
        ValidationResult $result
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

        if ($type === 'custom' && !array_key_exists('script', $raw)) {
            $result->addError("Job '$name': custom jobs require a 'script' key.");
            return null;
        }

        $config = $raw;
        unset($config['type']);

        return new self($name, $type, $config);
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
}
