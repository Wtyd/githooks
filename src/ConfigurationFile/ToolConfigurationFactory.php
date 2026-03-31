<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\ConfigurationFile;

use Wtyd\GitHooks\ConfigurationFile\Exception\ToolConfigurationDataIsNullException;
use Wtyd\GitHooks\ConfigurationFile\Exception\ToolIsNotSupportedException;
use Wtyd\GitHooks\Registry\ToolRegistry;
use Wtyd\GitHooks\Tools\Tool\CodeSniffer\Phpcbf;

class ToolConfigurationFactory
{
    protected array $configurationFile;

    protected ToolRegistry $toolRegistry;

    public function __construct(array $configurationFile, ToolRegistry $toolRegistry)
    {
        $this->configurationFile = $configurationFile;
        $this->toolRegistry = $toolRegistry;
    }

    /**
     * Builds a ToolConfiguration for the tool $toolName
     *
     * @param string $toolName The tool.
     * @param mixed $toolData The tool data must be an array but it is verified in ToolConfiguration.
     * @return ToolConfiguration
     *
     * @throws ToolConfigurationDataIsNullException
     */
    public function create(string $toolName, $toolData): ToolConfiguration
    {
        if (!$this->toolRegistry->isSupported($toolName)) {
            throw ToolIsNotSupportedException::forTool($toolName);
        }
        try {
            $toolConfiguration = null;
            if (ToolRegistry::PHPCBF === $toolName && Phpcbf::usePhpcsConfiguration($toolData)) {
                $phpcsConfiguration = new ToolConfiguration(ToolRegistry::PHPCS, $this->configurationFile[ToolRegistry::PHPCS], $this->toolRegistry);
                $phpcbfConfiguration = new ToolConfiguration($toolName, $toolData, $this->toolRegistry);

                $toolData = array_merge($phpcsConfiguration->getToolConfiguration(), $phpcbfConfiguration->getToolConfiguration());
            }

            $toolConfiguration = new ToolConfiguration($toolName, $toolData, $this->toolRegistry);
        } catch (\TypeError $error) {
            throw ToolConfigurationDataIsNullException::forData($toolName, $toolData);
        }

        return $toolConfiguration;
    }
}
