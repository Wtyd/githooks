<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\ConfigurationFile;

use Wtyd\GitHooks\ConfigurationFile\Exception\ConfigurationFileException;
use Wtyd\GitHooks\ConfigurationFile\Exception\ToolConfigurationDataIsNullException;
use Wtyd\GitHooks\ConfigurationFile\Exception\ToolIsNotSupportedException;
use Wtyd\GitHooks\Tools\Tool\ToolAbstract;

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
     * @var array of ToolConfiguration
     */
    protected $toolsConfiguration = [];

    /**
     * @var ToolConfigurationFactory
     */
    protected $toolConfigurationFactory;

    /**
     * @var array
     */
    protected $toolsErrors = [];

    /**
     * @var array
     */
    protected $toolsWarnings = [];

    /**
     * Checks data from configuration file
     *
     * @param array $configurationFile
     * @param string $tool The name of the tool or 'all'.
     *
     * @throws ToolIsNotSupportedException
     * @throws ConfigurationFileException
     */
    public function __construct(array $configurationFile, string $tool)
    {
        if (!$this->checkToolArgument($tool)) {
            throw ToolIsNotSupportedException::forTool($tool);
        }

        $this->configurationFile = $configurationFile;

        $this->toolConfigurationFactory = new ToolConfigurationFactory($this->configurationFile);

        $this->options = new OptionsConfiguration($this->configurationFile);

        $allToolsConfiguration = $this->extractConfigurationFromTools();

        $allTools = $this->createToolsConfiguration($allToolsConfiguration);

        if ($this->checkToolTag()) {
            $this->addTools($tool, $allTools);
        }

        if ($this->hasErrors()) {
            throw ConfigurationFileException::forFile($this);
        }
    }

    protected function checkToolArgument(string $tool): bool
    {
        if ($tool === self::ALL_TOOLS) {
            return true;
        }

        return $this->isSupportedTool($tool);
    }

    /**
     * @return array Only tools configuration from configuration file
     */
    protected function extractConfigurationFromTools(): array
    {
        $allToolsConfiguration = $this->configurationFile;
        unset($allToolsConfiguration[OptionsConfiguration::OPTIONS_TAG], $allToolsConfiguration[self::TOOLS]);
        return $allToolsConfiguration;
    }

    protected function createToolsConfiguration(array $allToolsInFile): array
    {
        $tools = [];
        foreach ($allToolsInFile as $toolName => $toolData) {
            try {
                $toolConfiguration  = $this->toolConfigurationFactory->create($toolName, $toolData);

                $tools[$toolName] =  $toolConfiguration;

                if (!$toolConfiguration->isEmptyWarnings()) {
                    $this->toolsWarnings = array_merge($this->toolsWarnings, $toolConfiguration->getWarnings());
                }
            } catch (ToolIsNotSupportedException $ex) {
                $this->toolsWarnings[] = "The tool $toolName is not supported by GitHooks.";
            } catch (ToolConfigurationDataIsNullException $ex) {
                $this->toolsErrors[] = "The tag '$toolName' is empty.";
            }
        }
        return $tools;
    }

    /**
     * 'Tools' tag must exist and not be empty
     *
     * @return boolean
     */
    protected function checkToolTag(): bool
    {
        if (!array_key_exists(self::TOOLS, $this->configurationFile)) {
            $this->toolsErrors[] = "There is no 'Tools' tag in the configuration file.";
            return false;
        }

        if (empty($this->configurationFile[self::TOOLS])) {
            $this->toolsErrors[] = "The 'Tools' tag from configuration file is empty.";
            return false;
        }

        return true;
    }

    /**
     * Only add the tools to run:
     * 1. When $toolName = 'all'-> all tools from 'Tools' tag
     * 2. When $toolName is a tool, this tool.
     *
     * @param string $toolName Tools to run.
     * @param array $allTools Array of ToolConfiguration with all tools of configuration file.
     * @return void
     */
    protected function addTools(string $toolName, array $allTools): void
    {
        if (self::ALL_TOOLS === $toolName) {
            $atLeastOneValidTool = false;
            foreach ($this->configurationFile[self::TOOLS] as $tool) {
                if ($this->isSupportedTool($tool) && array_key_exists($tool, $allTools)) {
                    $this->toolsConfiguration[$tool] = $allTools[$tool];
                    $atLeastOneValidTool = true;
                }
            }
            if (!$atLeastOneValidTool) {
                $this->toolsErrors[] = 'There must be at least one tool configured.';
            }
            return;
        }

        if (!$this->isToolTagMissing($toolName) && array_key_exists($toolName, $allTools)) {
            $this->toolsConfiguration[$toolName] = $allTools[$toolName];
        }
    }

    protected function isToolTagMissing(string $tool): bool
    {
        if (!array_key_exists($tool, $this->configurationFile)) {
            $this->toolsErrors[] = "The tag '$tool' is missing.";
            return true;
        }
        return false;
    }

    protected function isSupportedTool(string $tool): bool
    {
        if (ToolAbstract::checkTool($tool)) {
            return true;
        }

        $this->toolsWarnings[] = "The tool $tool is not supported by GitHooks.";
        return false;
    }


    protected function getOptionErrors(): array
    {
        return $this->options->getErrors();
    }

    protected function getOptionWarnings(): array
    {
        return $this->options->getWarnings();
    }

    public function getErrors(): array
    {
        return array_merge($this->getOptionErrors(), $this->toolsErrors);
    }

    public function getWarnings(): array
    {
        return array_merge($this->getOptionWarnings(), $this->toolsWarnings);
    }

    public function addToolsWarning(string $warning): void
    {
        $this->toolsWarnings[] = $warning;
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

    public function getToolsConfiguration(): array
    {
        return $this->toolsConfiguration;
    }
}
