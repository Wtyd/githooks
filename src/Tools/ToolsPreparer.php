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
     * @var ToolExecutor
     */
    protected $toolExecutor;

    /**
     * @var ConfigurationFile
     */
    protected $configurationFile;

    public function __construct(FileReader $fileReader, ExecutionFactory $executionFactory, ToolExecutor $toolExecutor)
    {
        $this->fileReader = $fileReader;
        $this->executionFactory = $executionFactory;
        $this->toolExecutor = $toolExecutor;
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
    public function __invoke(string $tool = ConfigurationFile::ALL_TOOLS, string $execution = ''): array
    {
        $file = $this->fileReader->readfile();

        $this->configurationFile = new ConfigurationFile($file);

        $this->setExecution($execution);

        $this->setTools($tool);

        if ($this->configurationFile->hasErrors()) {
            throw ConfigurationFileException::forFile($this->configurationFile);
        }

        $strategy = $this->executionFactory->__invoke($this->configurationFile->getExecution());

        return $strategy->getTools();
    }

    protected function setExecution(string $execution): void
    {
        if (empty($execution)) {
            return;
        }

        $this->configurationFile->setExecution($execution);
    }

    protected function setTools(string $tool): void
    {
        $this->configurationFile->setTools($tool);
    }
}
