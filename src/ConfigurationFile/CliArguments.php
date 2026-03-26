<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\ConfigurationFile;

use Wtyd\GitHooks\Tools\Tool\ToolAbstract;

class CliArguments
{
    /** @var string Name of the tool to be executed. 'all' for execute all tools setted in githooks.yml */
    protected $tool;

    /** @var string Mode execution. Can be 'fast' or 'full'. Default from githooks.yml. */
    protected $execution;

    /** @var bool|null */
    protected $ignoreErrorsOnExit;

    /** @var string */
    protected $otherArguments;

    /** @var string */
    protected $executablePath;

    /** @var string */
    protected $paths;

    /** @var int */
    protected $processes;

    /** @var string */
    protected $configFile;

    public function __construct(
        string $tool,
        string $execution,
        $ignoreErrorsOnExit,
        string $otherArguments,
        string $executablePath,
        string $paths,
        int $processes,
        string $configFile = ''
    ) {
        $this->tool = $tool;
        $this->execution = $execution;
        $this->ignoreErrorsOnExit = $this->stringToBool($ignoreErrorsOnExit);
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
            if ($this->ignoreErrorsOnExit !== null) {
                $allToolsConfiguration = $configurationFile;
                unset(
                    $allToolsConfiguration[OptionsConfiguration::OPTIONS_TAG],
                    $allToolsConfiguration[ConfigurationFile::TOOLS],
                    $allToolsConfiguration[ConfigurationFile::CLI_EXECUTION_OVERRIDE]
                );
                $tools = array_keys($allToolsConfiguration);
                foreach ($tools as $tool) {
                    $configurationFile[$tool][ToolAbstract::IGNORE_ERRORS_ON_EXIT] = $this->ignoreErrorsOnExit;
                }
            }
        } elseif (array_key_exists($this->tool, $configurationFile)) {
            $configurationFile[$this->tool] = $this->overrideToolArguments($configurationFile[$this->tool]);
        }

        return $configurationFile;
    }

    protected function overrideToolArguments(array $toolConfiguration): array
    {
        if ($this->ignoreErrorsOnExit !== null) {
            $toolConfiguration[ToolAbstract::IGNORE_ERRORS_ON_EXIT] = $this->ignoreErrorsOnExit;
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
