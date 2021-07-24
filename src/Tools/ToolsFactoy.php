<?php

namespace Wtyd\GitHooks\Tools;

use Illuminate\Container\Container;

class ToolsFactoy
{
    /**
     * Transform the array with the configuration of the tools into an array of tools.
     *
     * @param array $toolsConfiguration The key is the name of the tool and the value is Wtyd\GitHooks\ConfigurationFile\ToolConfiguration.
     *
     * @return array The key is the name of the tool and the value is the corresponding ToolAbstract instance.
     */
    public function __invoke(array $toolsConfiguration): array
    {
        $loadedTools = [];

        $container = Container::getInstance();
        foreach ($toolsConfiguration as $tool) {
            $loadedTools[$tool->getTool()] = $container->make(ToolAbstract::SUPPORTED_TOOLS[$tool->getTool()], [ToolAbstract::TOOL_CONFIGURATION => $tool]);
        }

        return $loadedTools;
    }
}
