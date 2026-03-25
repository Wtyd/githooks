<?php

namespace Wtyd\GitHooks\LoadTools;

use Wtyd\GitHooks\ConfigurationFile\ConfigurationFile;
use Wtyd\GitHooks\Tools\ToolsFactory;

/**
 * Prepares all the tools that are configured with the Tools tag in the configuration file.
 */
class FullExecution implements ExecutionMode
{
    /** @var \Wtyd\GitHooks\Tools\ToolsFactory */
    protected $toolsFactory;

    public function __construct(ToolsFactory $toolsFactory)
    {
        $this->toolsFactory = $toolsFactory;
    }

    /** @inheritDoc */
    public function getTools(ConfigurationFile $configurationFile): array
    {
        return $this->toolsFactory->__invoke($configurationFile->getToolsConfiguration());
    }
}
