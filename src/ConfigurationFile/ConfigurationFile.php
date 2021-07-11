<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\ConfigurationFile;

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

    public function __construct(array $configurationFile)
    {
        $this->configurationFile = $configurationFile;

        $this->options = new OptionsConfiguration($this->configurationFile);

        $this->setToolsConfiguration();
    }


    protected function setToolsConfiguration(): void
    {
        $atLeastOneValidTool = false;

        foreach ($this->configurationFile[self::TOOLS] as $tool) {
            if (ToolAbstract::CHECK_SECURITY === $tool) {
                $atLeastOneValidTool = true;
                continue;
            }

            if (!$this->checkSupportedTool($tool)) {
                continue;
            }

            if (!array_key_exists($tool, $this->configurationFile)) {
                $this->toolsErrors[] = "The tool $tool is not setted.";
            } else {
                $atLeastOneValidTool = true;

                $toolConfiguration = new ToolConfiguration($tool, $this->configurationFile[self::TOOLS][$tool]);
                $this->toolsConfiguration[$tool] = $toolConfiguration;

                if (!$toolConfiguration->isEmptyWarnings()) {
                    $this->toolsWarnings = array_merge($this->toolsWarnings, $toolConfiguration->getWarnings());
                }
            }
        }

        if (!$atLeastOneValidTool) {
            //FIXME comprobar si esto se valida tb en el FileReader
            $this->toolsErrors[] = 'There must be at least one tool configured.';
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
        $errors = array_merge($this->options->getErrors(), $this->toolsErrors);

        return !empty($errors);
    }

    public function getExecution(): string
    {
        $this->options->getExecution();
    }

    public function setExecution(string $execution)
    {
        $this->options->setExecution($execution);
    }



    public function setTools(string $tool): void
    {
        if ($tool === self::ALL_TOOLS) {
            return;
        }

        if (!$this->checkSupportedTool($tool)) {
            return;
        }
        if (!array_key_exists($tool, $this->toolsConfiguration)) {
            $this->toolsErrors = "The tool $tool is not configured in githooks.yml.";
        }
        $this->toolsConfiguration = $this->toolsConfiguration[$tool];
    }
}
