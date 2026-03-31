<?php

namespace Wtyd\GitHooks\Tools;

use Wtyd\GitHooks\LoadTools\ExecutionFactory;
use Wtyd\GitHooks\LoadTools\ExecutionMode;
use Wtyd\GitHooks\Registry\ToolRegistry;
use Wtyd\GitHooks\ConfigurationFile\ConfigurationFile;

class ToolsPreparer
{
    protected ExecutionFactory $executionFactory;

    protected ?ConfigurationFile $configurationFile = null;

    protected ToolRegistry $toolRegistry;

    public function __construct(ExecutionFactory $executionFactory, ToolRegistry $toolRegistry)
    {
        $this->executionFactory = $executionFactory;
        $this->toolRegistry = $toolRegistry;
    }

    /**
     * Returns the tools to run
     *
     * @param ConfigurationFile $configurationFile
     *
     * @return array<\Wtyd\GitHooks\Tools\Tool\ToolAbstract>
     */
    public function __invoke(ConfigurationFile $configurationFile): array
    {
        $this->configurationFile = $configurationFile;

        $fullTools = [];
        $fastTools = [];

        foreach ($configurationFile->getToolsConfiguration() as $name => $toolConfig) {
            $effectiveMode = $this->resolveEffectiveMode($toolConfig, $configurationFile);

            if ($effectiveMode === ExecutionMode::FAST_EXECUTION
                && !$this->toolRegistry->isAccelerable($toolConfig->getTool())
            ) {
                $this->addNonAccelerableWarning($toolConfig->getTool(), $configurationFile);
                $effectiveMode = ExecutionMode::FULL_EXECUTION;
            }

            if ($effectiveMode === ExecutionMode::FAST_EXECUTION) {
                $fastTools[$name] = $toolConfig;
            } else {
                $fullTools[$name] = $toolConfig;
            }
        }

        $tools = [];

        if (!empty($fullTools)) {
            $fullStrategy = $this->executionFactory->__invoke(ExecutionMode::FULL_EXECUTION);
            $tools = array_merge($tools, $fullStrategy->processTools($fullTools, $configurationFile));
        }

        if (!empty($fastTools)) {
            $fastStrategy = $this->executionFactory->__invoke(ExecutionMode::FAST_EXECUTION);
            $tools = array_merge($tools, $fastStrategy->processTools($fastTools, $configurationFile));
        }

        return $tools;
    }

    public function getConfigurationFileWarnings(): array
    {
        return $this->configurationFile !== null ? $this->configurationFile->getWarnings() : [];
    }

    /**
     * Resolve the effective execution mode for a tool.
     * Priority: CLI > per-tool > global > default ('full').
     *
     * @param \Wtyd\GitHooks\ConfigurationFile\ToolConfiguration $toolConfig
     * @param ConfigurationFile $configurationFile
     * @return string
     */
    protected function resolveEffectiveMode($toolConfig, ConfigurationFile $configurationFile): string
    {
        if ($configurationFile->isCLIExecutionOverride()) {
            return $configurationFile->getExecution();
        }

        return $toolConfig->getExecution() ?? $configurationFile->getExecution();
    }

    /**
     * Add warning for non-accelerable tools with fast mode, but only when explicit
     * (per-tool config or single-tool CLI run), not for `tool all fast`.
     *
     * @param string $toolName
     * @param ConfigurationFile $configurationFile
     * @return void
     */
    protected function addNonAccelerableWarning(string $toolName, ConfigurationFile $configurationFile): void
    {
        if ($configurationFile->isCLIExecutionOverride() && $configurationFile->isAllToolsRun()) {
            return;
        }

        $configurationFile->addToolsWarning(
            "Tool '$toolName' does not support fast execution. It will run in full mode."
        );
    }
}
