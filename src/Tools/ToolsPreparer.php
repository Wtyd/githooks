<?php

namespace Wtyd\GitHooks\Tools;

use Wtyd\GitHooks\ConfigurationFile\CliArguments;
use Wtyd\GitHooks\LoadTools\ExecutionFactory;
use Wtyd\GitHooks\ConfigurationFile\ConfigurationFile;
use Wtyd\GitHooks\ConfigurationFile\FileReader;

class ToolsPreparer
{
    /**
     * @var FileReader
     */
    protected $fileReader;

    /**
     * @var ExecutionFactory
     */
    protected $executionFactory;

    /**
     * @var ConfigurationFile
     */
    protected $configurationFile;

    public function __construct(FileReader $fileReader, ExecutionFactory $executionFactory)
    {
        $this->fileReader = $fileReader;
        $this->executionFactory = $executionFactory;
    }

    /**
     * Executes the tool(s) with the githooks.yml arguments.
     * The Option 'execution' can be overriden with the $execution variable.
     *
     * @param CliArguments $cliArguments
     *
     * @return array Tools (ToolAbstract) created and prepared for run.
     */
    public function __invoke(CliArguments $cliArguments): array
    {
        $file = $this->fileReader->readfile();

        $file = $cliArguments->overrideArguments($file);

        $this->configurationFile = new ConfigurationFile($file, $cliArguments->getTool());

        $strategy = $this->executionFactory->__invoke($this->configurationFile->getExecution());

        return $strategy->getTools($this->configurationFile);
    }

    protected function setExecution(string $execution): void
    {
        if (empty($execution)) {
            return;
        }

        $this->configurationFile->setExecution($execution);
    }

    public function getConfigurationFileWarnings(): array
    {
        return isset($this->configurationFile) ? $this->configurationFile->getWarnings() : [];
    }
}
