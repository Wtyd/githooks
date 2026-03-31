<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\ConfigurationFile;

use Wtyd\GitHooks\Tools\Tool\ToolAbstract;

class CliArguments
{
    protected string $tool;

    protected string $execution;

    protected ?bool $ignoreErrorsOnExit;

    protected ?bool $failFast;

    protected string $otherArguments;

    protected string $executablePath;

    protected string $paths;

    protected int $processes;

    protected string $configFile;

    /**
     * @param string|bool|null $ignoreErrorsOnExit
     * @param string|bool|null $failFast
     */
    public function __construct(
        string $tool,
        string $execution,
        $ignoreErrorsOnExit,
        string $otherArguments,
        string $executablePath,
        string $paths,
        int $processes,
        string $configFile = '',
        $failFast = null
    ) {
        $this->tool = $tool;
        $this->execution = $execution;
        $this->ignoreErrorsOnExit = $this->stringToBool($ignoreErrorsOnExit);
        $this->failFast = $this->stringToBool($failFast);
        $this->otherArguments = $otherArguments;
        $this->executablePath = $executablePath;
        $this->paths = $paths;
        $this->processes = $processes;
        $this->configFile = $configFile;
    }

    public function overrideArguments(array $configurationFile): array
    {
        if (!empty($this->execution)) {
            $configurationFile[OptionsConfiguration::OPTIONS_TAG][OptionsConfiguration::EXECUTION_TAG] = $this->execution;
            $configurationFile[ConfigurationFile::CLI_EXECUTION_OVERRIDE] = true;
        }

        if (!empty($this->processes)) {
            $configurationFile[OptionsConfiguration::OPTIONS_TAG][OptionsConfiguration::PROCESSES_TAG] = $this->processes;
        }

        if (ConfigurationFile::ALL_TOOLS === $this->tool) {
            $configurationFile = $this->overrideAllToolsArguments($configurationFile);
        } elseif (array_key_exists($this->tool, $configurationFile)) {
            $configurationFile[$this->tool] = $this->overrideToolArguments($configurationFile[$this->tool]);
        }

        return $configurationFile;
    }

    protected function overrideAllToolsArguments(array $configurationFile): array
    {
        if ($this->ignoreErrorsOnExit === null && $this->failFast === null) {
            return $configurationFile;
        }

        $allToolsConfiguration = $configurationFile;
        unset(
            $allToolsConfiguration[OptionsConfiguration::OPTIONS_TAG],
            $allToolsConfiguration[ConfigurationFile::TOOLS],
            $allToolsConfiguration[ConfigurationFile::CLI_EXECUTION_OVERRIDE]
        );

        foreach (array_keys($allToolsConfiguration) as $tool) {
            if ($this->ignoreErrorsOnExit !== null) {
                $configurationFile[$tool][ToolAbstract::IGNORE_ERRORS_ON_EXIT] = $this->ignoreErrorsOnExit;
            }
            if ($this->failFast !== null) {
                $configurationFile[$tool][ToolAbstract::FAIL_FAST] = $this->failFast;
            }
        }

        return $configurationFile;
    }

    protected function overrideToolArguments(array $toolConfiguration): array
    {
        if ($this->ignoreErrorsOnExit !== null) {
            $toolConfiguration[ToolAbstract::IGNORE_ERRORS_ON_EXIT] = $this->ignoreErrorsOnExit;
        }

        if ($this->failFast !== null) {
            $toolConfiguration[ToolAbstract::FAIL_FAST] = $this->failFast;
        }

        if (!empty($this->otherArguments)) {
            $toolConfiguration[ToolAbstract::OTHER_ARGS_OPTION] = $this->otherArguments;
        }

        if (!empty($this->executablePath)) {
            $toolConfiguration[ToolAbstract::EXECUTABLE_PATH_OPTION] = $this->executablePath;
        }

        if (!empty($this->paths)) {
            $toolConfiguration[ToolConfiguration::PATHS_TAG] = explode(',', $this->paths);
        }

        return $toolConfiguration;
    }

    public function getConfigFile(): string
    {
        return $this->configFile;
    }

    public function getTool(): string
    {
        return $this->tool;
    }

    /**
     * @param string|bool|null $value
     * @return bool|null
     */
    protected function stringToBool($value)
    {
        if (is_bool($value)) {
            return $value;
        }

        if ('true' === $value) {
            return true;
        }

        if ('false' === $value) {
            return false;
        }

        return null;
    }
}
