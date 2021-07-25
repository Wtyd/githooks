<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\ConfigurationFile;

use Wtyd\GitHooks\Tools\ToolAbstract;

class ToolConfiguration
{
    /**
     * The tool arguments. The keys of the array must be the tool::OPTIONS
     *
     * @var array
     */
    protected $toolConfiguration;

    /**
     * @var string Name of the tool. It must be some of the ToolAbstract::SUPPORTED_TOOLS
     */
    protected $tool;

    /**
     * @var array
     */
    protected $warnings = [];

    public function __construct(string $tool, array $toolConfiguration)
    {
        $this->toolConfiguration = $toolConfiguration;
        $this->tool = $tool;
        $this->checkConfiguration();
    }

    /**
     * Check that each key of $toolConfiguration exists in the tool OPTIONS array.
     *
     * @return void
     */
    protected function checkConfiguration(): void
    {
        $warnings = [];

        foreach (array_keys($this->toolConfiguration) as $key) {
            if (!in_array($key, ToolAbstract::SUPPORTED_TOOLS[$this->tool]::OPTIONS)) {
                $warnings[] = "$key argument is invalid for tool $this->tool. It will be ignored.";
            }
        }

        $this->warnings = $warnings;
    }

    public function getToolConfiguration(): array
    {
        return $this->toolConfiguration;
    }

    public function getTool(): string
    {
        return $this->tool;
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function isEmptyWarnings(): bool
    {
        return empty($this->warnings);
    }

    public function getPaths(): array
    {
        return array_key_exists('paths', $this->toolConfiguration) ? $this->toolConfiguration['paths'] : [];
    }

    public function setPaths(array $paths): void
    {
        $this->toolConfiguration['paths'] = $paths;
    }
}
