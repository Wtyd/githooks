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

    /** @var bool|string */
    protected $ignoreErrorsOnExit;

    /** @var string */
    protected $otherArguments;

    /** @var string */
    protected $executablePath;

    /** @var string */
    protected $paths;

    public function __construct(
        string $tool,
        string $execution,
        $ignoreErrorsOnExit,
        string $otherArguments,
        string $executablePath,
        string $paths
    ) {
        $this->tool = $tool;
        $this->execution = $execution;
        $this->ignoreErrorsOnExit = $this->stringToBool($ignoreErrorsOnExit);
        $this->otherArguments = $otherArguments;
        $this->executablePath = $executablePath;
        $this->paths = $paths;
    }

    public function overrideArguments(array $configurationFile): array
    {
        if (!empty($this->execution)) {
            $configurationFile[OptionsConfiguration::OPTIONS_TAG]['execution'] = $this->execution;
        }

        if ('all' === $this->tool) {
            if (is_bool($this->ignoreErrorsOnExit)) {
                $allToolsConfiguration = $configurationFile;
                unset($allToolsConfiguration[OptionsConfiguration::OPTIONS_TAG], $allToolsConfiguration[ConfigurationFile::TOOLS]);
                $tools = array_keys($allToolsConfiguration);
                foreach ($tools as $tool) {
                    $configurationFile[$tool][ToolAbstract::IGNORE_ERRORS_ON_EXIT] = $this->ignoreErrorsOnExit;
                }
            }
        } else {
            if (is_bool($this->ignoreErrorsOnExit)) {
                $configurationFile[$this->tool][ToolAbstract::IGNORE_ERRORS_ON_EXIT] = $this->ignoreErrorsOnExit;
            }

            if (!empty($this->otherArguments)) {
                $configurationFile[$this->tool][ToolAbstract::OTHER_ARGS_OPTION] = $this->otherArguments;
            }

            if (!empty($this->executablePath)) {
                $configurationFile[$this->tool][ToolAbstract::EXECUTABLE_PATH_OPTION] = $this->executablePath;
            }

            if (!empty($this->paths)) {
                $configurationFile[$this->tool]['paths'] = explode(',', $this->paths);
            }
        }

        return $configurationFile;
    }

    public function getTool(): string
    {
        return $this->tool;
    }

    /**
     * @param string|null $string
     * @return bool|string
     */
    protected function stringToBool($string)
    {
        if (null === $string) {
            return '';
        }
        if ('true' === $string) {
            return true;
        }

        if ('false' === $string) {
            return false;
        }

        return $string;
    }
}
