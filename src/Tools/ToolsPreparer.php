<?php

namespace Wtyd\GitHooks\Tools;

use Wtyd\GitHooks\LoadTools\ExecutionFactory;
use Wtyd\GitHooks\ConfigurationFile\ConfigurationFile;

class ToolsPreparer
{
    /**
     * @var ExecutionFactory
     */
    protected $executionFactory;

    /**
     * @var ConfigurationFile
     */
    protected $configurationFile;

    public function __construct(ExecutionFactory $executionFactory)
    {
        $this->executionFactory = $executionFactory;
    }

    /**
     * Executes the tool(s) with the githooks.yml arguments.
     * The Option 'execution' can be overriden with the $execution variable.
     *
     * @param ConfigurationFile $cliArguments
     *
     * @return array<ToolAbstract> created and prepared for run.
     */
    public function __invoke(ConfigurationFile $configurationFile): array
    {
        $this->configurationFile = $configurationFile;

        $strategy = $this->executionFactory->__invoke($configurationFile->getExecution());

        return $strategy->getTools($configurationFile);
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
