<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\ConfigurationFile;

use Wtyd\GitHooks\Tools\Tool\ToolAbstract;

class ReadConfigurationFileAction
{
    /** @var FileReader */
    protected $fileReader;

    public function __construct(FileReader $fileReader)
    {
        $this->fileReader = $fileReader;
    }

    public function __invoke(CliArguments $cliArguments): ConfigurationFile
    {
        $file = $this->fileReader->readfile($cliArguments->getConfigFile());

        $file = $this->resolveScriptName($file);

        $file = $cliArguments->overrideArguments($file);

        return new ConfigurationFile($file, $cliArguments->getTool());
    }

    /**
     * If the 'script' section has a 'name' attribute, renames the config key
     * from 'script' to the custom name and registers the alias in ToolAbstract.
     *
     * @param array $file
     * @return array
     */
    protected function resolveScriptName(array $file): array
    {
        if (!isset($file[ToolAbstract::SCRIPT]['name'])) {
            return $file;
        }

        $name = $file[ToolAbstract::SCRIPT]['name'];

        if (empty($name) || !is_string($name)) {
            return $file;
        }

        ToolAbstract::registerScriptAlias($name);

        $file[$name] = $file[ToolAbstract::SCRIPT];
        unset($file[$name]['name']);
        unset($file[ToolAbstract::SCRIPT]);

        return $file;
    }
}
