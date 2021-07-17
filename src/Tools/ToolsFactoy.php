<?php

namespace Wtyd\GitHooks\Tools;

use Wtyd\GitHooks\LoadTools\Exception\ToolDoesNotExistException;
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
            if (!array_key_exists($tool->getTool(), ToolAbstract::SUPPORTED_TOOLS)) {
                //TODO esto en principio ya está validado
                throw ToolDoesNotExistException::forTool($tool);
            }

            // CHECK_SECURITY don't need configuration
            if (ToolAbstract::CHECK_SECURITY === $tool->getTool()) {
                $loadedTools[$tool->getTool()] = $container->make(ToolAbstract::SUPPORTED_TOOLS[$tool->getTool()]);
            } else {
                $loadedTools[$tool->getTool()] = $container->make(ToolAbstract::SUPPORTED_TOOLS[$tool->getTool()], [ToolAbstract::TOOL_CONFIGURATION => $tool]);
            }
        }

        return $loadedTools;
    }
}
