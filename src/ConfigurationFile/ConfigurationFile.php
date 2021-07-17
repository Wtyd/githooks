<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\ConfigurationFile;

use Wtyd\GitHooks\ConfigurationFile\Exception\ToolIsNotSupportedException;
use Wtyd\GitHooks\Tools\ToolAbstract;

class ConfigurationFile
{
    public const CONFIGURATION_FILE = 'configurationFile';

    public const TOOLS = 'Tools';

    public const ALL_TOOLS = 'all';

    /**
     * @var array
     */
    protected $configurationFile;

    /**
     * @var OptionsConfiguration
     */
    protected $options;

    /**
     * @var array
     */
    protected $toolsConfiguration = [];

    /**
     * @var array
     */
    protected $toolsErrors = [];

    /**
     * @var array
     */
    protected $toolsWarnings = [];

    public function __construct(array $configurationFile, string $tool)
    {
        $this->configurationFile = $configurationFile;

        $this->options = new OptionsConfiguration($this->configurationFile);

        if ($this->checkToolArgument($tool)) {
            $this->setToolsConfiguration($tool);
        } else {
            throw ToolIsNotSupportedException::forTool($tool);
        }
    }


    protected function setToolsConfiguration(string $tool): void
    {
        if ($tool === self::ALL_TOOLS) {
            $atLeastOneValidTool = false;

            foreach ($this->configurationFile[self::TOOLS] as $tool) {
                if (ToolAbstract::CHECK_SECURITY === $tool) {
                    $atLeastOneValidTool = true;
                    continue;
                }

                if (!$this->checkSupportedTool($tool)) {
                    continue;
                }
                $this->addToolConfiguration($tool);
                $atLeastOneValidTool = true;
            }
            if (!$atLeastOneValidTool) {
                //FIXME comprobar si esto se valida tb en el FileReader
                $this->toolsErrors[] = 'There must be at least one tool configured.';
            }
        } else {
            $this->addToolConfiguration($tool);
        }
    }

    protected function addToolConfiguration(string $tool): void
    {
        if (!array_key_exists($tool, $this->configurationFile)) {
            $this->toolsErrors[] = "The tag '$tool' is missing.";
        } else {
            $toolConfiguration = new ToolConfiguration($tool, $this->configurationFile[$tool]);
            $this->toolsConfiguration[$tool] = $toolConfiguration;

            if (!$toolConfiguration->isEmptyWarnings()) {
                $this->toolsWarnings = array_merge($this->toolsWarnings, $toolConfiguration->getWarnings());
            }
        }
    }

    protected function checkSupportedTool(string $tool): bool
    {
        if (array_key_exists($tool, ToolAbstract::SUPPORTED_TOOLS)) {
            return true;
        }

        $this->toolsWarnings[] = "The tool $tool is not supported by GitHooks.";
        return false;
    }

    public function getToolsErrors(): array
    {
        return $this->toolsErrors;
    }

    public function getToolsWarnings(): array
    {
        return $this->toolsWarnings;
    }

    public function getOptionErrors(): array
    {
        return $this->options->getErrors();
    }

    public function hasErrors(): bool
    {
        $errors = array_merge($this->getOptionErrors(), $this->toolsErrors);

        return !empty($errors);
    }

    public function getExecution(): string
    {
        return $this->options->getExecution();
    }

    public function setExecution(string $execution): void
    {
        $this->options->setExecution($execution);
    }

    /**
     * Set the tools to be run:
     * 1. If $tool is 'all', do nothing (it will run all tools setted in githooks.yml)
     * 2. Check is $tool is supported by GitHooks
     * 3. Check if $tool is setted in githooks.yml
     * 4. Set only the $tool
     *
     * @param string $tool The name of the tool.
     *
     * @return void
     */
    protected function checkToolArgument(string $tool): bool
    {
        if ($tool === self::ALL_TOOLS) {
            return true;
        }

        return $this->checkSupportedTool($tool);
    }

    public function getToolsConfiguration(): array
    {
        return $this->toolsConfiguration;
    }
}
