<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\ConfigurationFile;

use Wtyd\GitHooks\ConfigurationFile\Exception\ToolConfigurationDataIsNullException;
use Wtyd\GitHooks\LoadTools\ExecutionMode;
use Wtyd\GitHooks\Registry\ToolRegistry;
use Wtyd\GitHooks\Tools\Tool\ToolAbstract;

class ToolConfiguration
{
    public const PATHS_TAG = 'paths';

    public const EXECUTION_TAG = 'execution';

    protected array $toolConfiguration;

    protected string $tool;

    protected ?string $execution = null;

    protected array $warnings = [];

    protected ToolRegistry $toolRegistry;

    public function __construct(string $tool, array $toolConfiguration, ToolRegistry $toolRegistry)
    {
        $this->tool = $tool;
        $this->toolRegistry = $toolRegistry;
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

        $this->extractExecution($warnings);

        $resolvedTool = $this->toolRegistry->resolve($this->tool);
        $validOptions = $this->toolRegistry->getClass($resolvedTool)::ARGUMENTS;

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

        $warning = $this->setFailFastOption();

        if (!empty($warning)) {
            $warnings[] = $warning;
        }

        $this->checkFailFastConflict($warnings);

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
        return array_key_exists(self::PATHS_TAG, $this->toolConfiguration) ? $this->toolConfiguration[self::PATHS_TAG] : [];
    }

    public function setPaths(array $paths): void
    {
        $this->toolConfiguration[self::PATHS_TAG] = $paths;
    }

    /**
     * @return string|null Per-tool execution mode, or null if not set (inherit global).
     */
    public function getExecution()
    {
        return $this->execution;
    }

    public function hasExecution(): bool
    {
        return $this->execution !== null;
    }

    /**
     * Set value for failFast. If not bool value it sets warning and set the option to 'false'.
     *
     * @return string Warning if not bool value. Empty if otherwise.
     */
    protected function setFailFastOption(): string
    {
        $warning = '';
        if (!array_key_exists(ToolAbstract::FAIL_FAST, $this->toolConfiguration)) {
            return $warning;
        }

        if (!is_bool($this->toolConfiguration[ToolAbstract::FAIL_FAST])) {
            $warning = "Value for '" . ToolAbstract::FAIL_FAST . "' in tool $this->tool must be boolean. This option will be ignored.";
            $this->toolConfiguration[ToolAbstract::FAIL_FAST] = false;
        }

        return $warning;
    }

    /**
     * Warn when both failFast and ignoreErrorsOnExit are true.
     * failFast takes priority — ignoreErrorsOnExit will be ignored for this tool.
     *
     * @param array &$warnings
     * @return void
     */
    protected function checkFailFastConflict(array &$warnings): void
    {
        $hasFailFast = array_key_exists(ToolAbstract::FAIL_FAST, $this->toolConfiguration)
            && $this->toolConfiguration[ToolAbstract::FAIL_FAST] === true;

        $hasIgnoreErrors = array_key_exists(ToolAbstract::IGNORE_ERRORS_ON_EXIT, $this->toolConfiguration)
            && $this->toolConfiguration[ToolAbstract::IGNORE_ERRORS_ON_EXIT] === true;

        if ($hasFailFast && $hasIgnoreErrors) {
            $warnings[] = "Tool $this->tool has both 'failFast' and 'ignoreErrorsOnExit' set to true. "
                . "'failFast' takes priority — 'ignoreErrorsOnExit' will be ignored.";
            $this->toolConfiguration[ToolAbstract::IGNORE_ERRORS_ON_EXIT] = false;
        }
    }

    /**
     * Extract and validate per-tool execution mode from the configuration array.
     * Removes it from $toolConfiguration so it does not reach tool's setArguments().
     *
     * @param array &$warnings
     * @return void
     */
    protected function extractExecution(array &$warnings): void
    {
        if (!array_key_exists(self::EXECUTION_TAG, $this->toolConfiguration)) {
            return;
        }

        $value = $this->toolConfiguration[self::EXECUTION_TAG];
        unset($this->toolConfiguration[self::EXECUTION_TAG]);

        if (is_string($value) && in_array($value, ExecutionMode::EXECUTION_KEY, true)) {
            $this->execution = $value;
            return;
        }

        $warnings[] = "Value '$value' for 'execution' in tool $this->tool is not valid. Valid values: "
            . implode(', ', ExecutionMode::EXECUTION_KEY) . ". This option will be ignored.";
    }
}
