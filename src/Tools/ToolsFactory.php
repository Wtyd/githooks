<?php

namespace Wtyd\GitHooks\Tools;

use Illuminate\Container\Container;
use Wtyd\GitHooks\Registry\ToolRegistry;
use Wtyd\GitHooks\Tools\Tool\ToolAbstract;

class ToolsFactory
{
    protected ToolRegistry $toolRegistry;

    public function __construct(ToolRegistry $toolRegistry)
    {
        $this->toolRegistry = $toolRegistry;
    }

    /**
     * Transform the array with the configuration of the tools into an array of tools.
     *
     * @param array<\Wtyd\GitHooks\ConfigurationFile\ToolConfiguration> $toolsConfiguration The key is the name of the tool.
     *
     * @return array<\Wtyd\GitHooks\Tools\Tool\ToolAbstract> The key is the name of the tool and the value is the corresponding ToolAbstract instance.
     */
    public function __invoke(array $toolsConfiguration): array
    {
        $loadedTools = [];

        $container = Container::getInstance();
        foreach ($toolsConfiguration as $tool) {
            $resolvedTool = $this->toolRegistry->resolve($tool->getTool());
            $loadedTools[$tool->getTool()] = $container->make($this->toolRegistry->getClass($resolvedTool), [ToolAbstract::TOOL_CONFIGURATION => $tool]);
        }

        return $loadedTools;
    }
}
