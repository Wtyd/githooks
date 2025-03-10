<?php

namespace Wtyd\GitHooks\Tools;

use Illuminate\Container\Container;
use Wtyd\GitHooks\Tools\Tool\ToolAbstract;
use Wtyd\GitHooks\Tools\Tool\{
    Phpcpd,
    SecurityChecker,
    MessDetector,
    ParallelLint,
    Phpstan
};
use Wtyd\GitHooks\Tools\Tool\CodeSniffer\{
    Phpcs,
    Phpcbf
};

class ToolsFactoy
{
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
            $loadedTools[$tool->getTool()] = $container->make(ToolAbstract::SUPPORTED_TOOLS[$tool->getTool()], [ToolAbstract::TOOL_CONFIGURATION => $tool]);
        }

        return $loadedTools;
    }
}
