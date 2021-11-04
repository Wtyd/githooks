<?php

namespace Wtyd\GitHooks\Tools;

use Wtyd\GitHooks\LoadTools\ExecutionFactory;
use Wtyd\GitHooks\ConfigurationFile\ConfigurationFile;
use Wtyd\GitHooks\ConfigurationFile\Exception\ConfigurationFileException;
use Wtyd\GitHooks\ConfigurationFile\FileReader;
use Wtyd\GitHooks\Tools\ToolExecutor;

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
     * @param string $tool Name of the tool to be executed. 'all' for execute all tools setted in githooks.yml
     * @param string $execution Mode execution. Can be 'smart', 'fast' or 'full'. Default from githooks.yml.
     *
     * @return array Tools (ToolAbstract) created and prepared for run.
     */
    public function __invoke(string $tool, string $execution = ''): array
    {
        $file = $this->fileReader->readfile();

        $this->configurationFile = new ConfigurationFile($file, $tool);

        $this->setExecution($execution);

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
}
