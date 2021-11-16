<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\ConfigurationFile;

use Wtyd\GitHooks\ConfigurationFile\Exception\ConfigurationFileException;
use Wtyd\GitHooks\ConfigurationFile\Exception\ToolIsNotSupportedException;
use Wtyd\GitHooks\Tools\Tool\CodeSniffer\Phpcbf;
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
     * @var array
     */
    protected $toolsErrors = [];

    /**
     * @var array
     */
    protected $toolsWarnings = [];

    public function __construct(array $configurationFile, string $tool)
    {
        if (!$this->checkToolArgument($tool)) {
            throw ToolIsNotSupportedException::forTool($tool);
        }

        $this->configurationFile = $configurationFile;

        $this->options = new OptionsConfiguration($this->configurationFile);


        if ($this->checkToolTag()) {
            $this->setToolsConfiguration($tool);
        }

        if ($this->hasErrors()) {
            throw ConfigurationFileException::forFile($this);
        }
    }

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

    protected function setToolsConfiguration(string $tool): void
    {
        if ($tool === self::ALL_TOOLS) {
            $atLeastOneValidTool = false;

            foreach ($this->configurationFile[self::TOOLS] as $tool) {
                if (!$this->checkSupportedTool($tool)) {
                    continue;
                }
                if (!$atLeastOneValidTool) {
                    $atLeastOneValidTool = $this->addTool($tool);
                } else {
                    $this->addTool($tool);
                }
            }
            if (!$atLeastOneValidTool) {
                $this->toolsErrors[] = 'There must be at least one tool configured.';
            }
        } else {
            $this->addTool($tool);
        }
    }

    protected function addTool(string $tool): bool
    {
        if ($this->toolShouldBeAdded($tool)) {
            $toolConfiguration = null;
            if (ToolAbstract::PHPCBF === $tool && Phpcbf::usePhpcsConfiguration($this->configurationFile[$tool])) {
                $phpcsConfiguration = new ToolConfiguration(ToolAbstract::CODE_SNIFFER, $this->configurationFile[ToolAbstract::CODE_SNIFFER]);
                $phpcbfConfiguration = new ToolConfiguration($tool, $this->configurationFile[$tool]);
                $configuration = array_merge($phpcsConfiguration->getToolConfiguration(), $phpcbfConfiguration->getToolConfiguration());
                // dd($configuration);
                $phpcbfConfiguration->setToolConfiguration($configuration);
                $toolConfiguration = $phpcbfConfiguration;
            } else {
                $toolConfiguration = new ToolConfiguration($tool, $this->configurationFile[$tool]);
            }

            $this->toolsConfiguration[$tool] = $toolConfiguration;

            if (!$toolConfiguration->isEmptyWarnings()) {
                $this->toolsWarnings = array_merge($this->toolsWarnings, $toolConfiguration->getWarnings());
            }
            return true;
        }
        return false;
    }

    protected function toolShouldBeAdded(string $tool): bool
    {
        if (!array_key_exists($tool, $this->configurationFile)) {
            $this->toolsErrors[] = "The tag '$tool' is missing.";
            return false;
        }
        return true;
    }

    protected function checkSupportedTool(string $tool): bool
    {
        if (array_key_exists($tool, ToolAbstract::SUPPORTED_TOOLS)) {
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
