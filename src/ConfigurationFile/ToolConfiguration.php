<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\ConfigurationFile;

use Wtyd\GitHooks\Tools\Tool\ToolAbstract;

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
        $this->tool = $tool;
        $this->setToolConfiguration($toolConfiguration);
    }

    /**
     * Check that each key of $toolConfiguration exists in the tool OPTIONS array.
     * If not exists adds a warning and delete de key from the $toolConfiguration array.
     *
     * @return void
     */
    protected function checkConfiguration(): void
    {
        $warnings = [];

        $validOptions = ToolAbstract::SUPPORTED_TOOLS[$this->tool]::OPTIONS;

        $validOptions[] = ToolAbstract::EXECUTABLE_PATH_OPTION;

        foreach (array_keys($this->toolConfiguration) as $key) {
            if (!in_array($key, $validOptions)) {
                $warnings[] = "$key argument is invalid for tool $this->tool. It will be ignored.";
                unset($this->toolConfiguration[$key]);
            }
        }

        $this->warnings = $warnings;
    }

    public function getToolConfiguration(): array
    {
        return $this->toolConfiguration;
    }

    protected function setToolConfiguration(array $configuration): void
    {
        $this->toolConfiguration = $configuration;
        $this->warnings = [];
        $this->checkConfiguration();
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
