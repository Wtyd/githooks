<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\ConfigurationFile;

use Wtyd\GitHooks\Registry\ToolRegistry;

class ReadConfigurationFileAction
{
    protected FileReader $fileReader;

    protected ToolRegistry $toolRegistry;

    public function __construct(FileReader $fileReader, ToolRegistry $toolRegistry)
    {
        $this->fileReader = $fileReader;
        $this->toolRegistry = $toolRegistry;
    }

    public function __invoke(CliArguments $cliArguments): ConfigurationFile
    {
        $file = $this->fileReader->readfile($cliArguments->getConfigFile());

        $file = $this->resolveScriptName($file);

        $file = $cliArguments->overrideArguments($file);

        return new ConfigurationFile($file, $cliArguments->getTool(), $this->toolRegistry);
    }

    /**
     * If the 'script' section has a 'name' attribute, renames the config key
     * from 'script' to the custom name and registers the alias.
     *
     * @param array $file
     * @return array
     */
    protected function resolveScriptName(array $file): array
    {
        return $this->toolRegistry->resolveScriptName($file);
    }
}
