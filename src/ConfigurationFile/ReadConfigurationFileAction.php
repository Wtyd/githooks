<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\ConfigurationFile;

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
        $file = $this->fileReader->readfile();

        $file = $cliArguments->overrideArguments($file);

        return new ConfigurationFile($file, $cliArguments->getTool());
    }
}
