<?php

namespace Wtyd\GitHooks\LoadTools;

use Wtyd\GitHooks\ConfigurationFile\ConfigurationFile;
use Wtyd\GitHooks\Registry\ToolRegistry;
use Wtyd\GitHooks\Tools\ToolsFactory;
use Wtyd\GitHooks\Utils\FileUtilsInterface;

/**
 * This strategy runs the tools only against files modified by commit.
 * 1. This option only affects tools that support fast execution (SUPPORTS_FAST = true). The other tools will run as the full option.
 * 2. WARNING!!! You must set the excludes of the tools either in githooks.yml or in the configuration file of each tool since this option overwrites the key
 * paths of the tools so that they are executed only against the modified files.
 */
class FastExecution implements ExecutionMode
{

    protected FileUtilsInterface $fileUtils;

    protected ToolsFactory $toolsFactory;

    protected ToolRegistry $toolRegistry;

    public function __construct(FileUtilsInterface $fileUtils, ToolsFactory $toolsFactory, ToolRegistry $toolRegistry)
    {
        $this->fileUtils = $fileUtils;
        $this->toolsFactory = $toolsFactory;
        $this->toolRegistry = $toolRegistry;
    }

    /** @inheritDoc */
    public function getTools(ConfigurationFile $configurationFile): array
    {
        return $this->processTools($configurationFile->getToolsConfiguration(), $configurationFile);
    }

    /** @inheritDoc */
    public function processTools(array $toolConfigurations, ConfigurationFile $configurationFile): array
    {
        $tools = [];
        foreach ($toolConfigurations as $tool) {
            if (!$this->toolRegistry->isAccelerable($tool->getTool())) {
                $tools[] = $tool;
                continue;
            }

            $originalPaths = $tool->getPaths();
            $modifiedFiles = $this->fileUtils->getModifiedFiles();

            $paths = $this->addFilesToToolPaths($modifiedFiles, $originalPaths);

            if (!empty($paths)) {
                $tool->setPaths($paths);
                $tools[] = $tool;
            } else {
                $configurationFile->addToolsWarning('The tool ' . $tool->getTool() . ' was skipped.');
            }
        }

        return $this->toolsFactory->__invoke($tools);
    }

    /**
     * Only add to $paths the $modifiedFiles that are in the $originalPaths
     *
     * @param array $modifiedFiles  Files modified by the commit.
     * @param array $originalPaths Paths setted for each tool in githooks.yml
     * @return array Files that are in the $originalPaths.
     */
    protected function addFilesToToolPaths(array $modifiedFiles, array $originalPaths): array
    {
        $paths = [];

        foreach ($modifiedFiles as $file) {
            if ($this->fileIsInPaths($file, $originalPaths)) {
                $paths[] = $file;
            }
        }
        return $paths;
    }

    protected function fileIsInPaths(string $file, array $paths): bool
    {
        foreach ($paths as $path) {
            if (is_file($path) && $this->fileUtils->isSameFile($file, $path)) {
                return true;
            }

            if ($this->fileUtils->directoryContainsFile($path, $file)) {
                return true;
            }

            continue;
        }

        return false;
    }
}
