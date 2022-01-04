<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\ConfigurationFile;

use Wtyd\GitHooks\ConfigurationFile\Exception\ToolConfigurationDataIsNullException;
use Wtyd\GitHooks\ConfigurationFile\Exception\ToolIsNotSupportedException;
use Wtyd\GitHooks\Tools\Tool\CodeSniffer\Phpcbf;
use Wtyd\GitHooks\Tools\Tool\ToolAbstract;

class ToolConfigurationFactory
{
    /**
     * @var array
     */
    protected $configurationFile;

    public function __construct(array $configurationFile)
    {
        $this->configurationFile = $configurationFile;
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
        if (!ToolAbstract::checkTool($toolName)) {
            throw ToolIsNotSupportedException::forTool($toolName);
        }
        try {
            $toolConfiguration = null;
            if (ToolAbstract::PHPCBF === $toolName && Phpcbf::usePhpcsConfiguration($toolData)) {
                $phpcsConfiguration = new ToolConfiguration(ToolAbstract::PHPCS, $this->configurationFile[ToolAbstract::PHPCS]);
                $phpcbfConfiguration = new ToolConfiguration($toolName, $toolData);

                $toolData = array_merge($phpcsConfiguration->getToolConfiguration(), $phpcbfConfiguration->getToolConfiguration());
            }

            $toolConfiguration = new ToolConfiguration($toolName, $toolData);
        } catch (\TypeError $error) {
            throw ToolConfigurationDataIsNullException::forData($toolName, $toolData);
        }

        return $toolConfiguration;
    }
}
