<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\ConfigurationFile;

use Wtyd\GitHooks\ConfigurationFile\Exception\ToolConfigurationDataIsNullException;
use Wtyd\GitHooks\Tools\Tool\ToolAbstract;

class ToolConfiguration
{
    /**
     * The tool arguments. The keys of the array must be the tool::ARGUMENTS
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
     * Check that each key of $toolConfiguration exists in the tool ARGUMENTS array.
     * If not exists adds a warning and delete de key from the $toolConfiguration array.
     *
     * @return void
     */
    protected function checkConfiguration(): void
    {
        if (empty($this->toolConfiguration)) {
            throw ToolConfigurationDataIsNullException::forData($this->tool, $this->toolConfiguration);
        }
        $warnings = [];

        $validOptions = ToolAbstract::SUPPORTED_TOOLS[$this->tool]::ARGUMENTS;

        // TODO $validOptions just have EXECUTABLE_PATH_OPTION
        $validOptions[] = ToolAbstract::EXECUTABLE_PATH_OPTION;

        foreach (array_keys($this->toolConfiguration) as $key) {
            if (!in_array($key, $validOptions)) {
                $warnings[] = "$key argument is invalid for tool $this->tool. It will be ignored.";
                unset($this->toolConfiguration[$key]);
            }
        }
        $warning = $this->setIgnoreErrorsOnExitOption();

        if (!empty($warning)) {
            $warnings[] = $warning;
        }
        $this->warnings = $warnings;
    }

    /**
     * Set value for ignoreErrorsOnExit. If not bool value it sets warning and set the option to 'false'.
     *
     * @return string Warning if not bool value. Empty if otherwise.
     */
    protected function setIgnoreErrorsOnExitOption(): string
    {
        $warning = '';
        if (!array_key_exists(ToolAbstract::IGNORE_ERRORS_ON_EXIT, $this->toolConfiguration)) {
            return $warning;
        }

        if (!is_bool($this->toolConfiguration[ToolAbstract::IGNORE_ERRORS_ON_EXIT])) {
            $warning = "Value for'" . ToolAbstract::IGNORE_ERRORS_ON_EXIT . "'in tool $this->tool must be boolean. This option will be ignored.";
            $this->toolConfiguration[ToolAbstract::IGNORE_ERRORS_ON_EXIT] = false;
        }

        return $warning;
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
