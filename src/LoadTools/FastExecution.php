<?php

namespace Wtyd\GitHooks\LoadTools;

use Wtyd\GitHooks\ConfigurationFile\ConfigurationFile;
use Wtyd\GitHooks\Tools\Tool\ToolAbstract;
use Wtyd\GitHooks\Tools\ToolsFactoy;
use Wtyd\GitHooks\Utils\FileUtilsInterface;

/**
 * This strategy runs the tools only against files modified by commit.
 * 1. This option only affects the following tools: phpcs, phpmd, phpstan, and parallel-lint (ACCELERABLE_TOOLS). The other tools will run as the full
 * option.
 * 2. WARNING!!! You must set the excludes of the tools either in githooks.yml or in the configuration file of eath tool since this option overwrites the key
 * paths of the tools so that they are executed only against the modified files.
 */
class FastExecution implements ExecutionMode
{

    /**
     * Tools which execution can be improve by ExecutionMode. The following tools are not affected:
     * 1. Check-Security no executes against any path.
     * 2. Copy Paste Detector needs all files to be able to compare
     */
    public const ACCELERABLE_TOOLS = [
        ToolAbstract::CODE_SNIFFER,
        ToolAbstract::PARALLEL_LINT,
        ToolAbstract::MESS_DETECTOR,
        ToolAbstract::PHPSTAN,
    ];

    /**
     * @var FileUtilsInterface
     */
    protected $fileUtils;

    /**
     * @var ToolsFactoy
     */
    protected $toolsFactory;

    public function __construct(FileUtilsInterface $fileUtils, ToolsFactoy $toolsFactory)
    {
        $this->fileUtils = $fileUtils;
        $this->toolsFactory = $toolsFactory;
    }

    /**
     * Se cargan todas las herramientas configuradas como en la FullExecution. La diferencia es que editamos en el fichero de configuración los 'paths'
     * contra los que se ejecutan las herramientas. Ahora, en lugar de contra directorios 'pahts' se ejecuta contra los ficheros modificados que pertenezcan
     * a esos 'paths' o a sus subdirectorios.
     * Si un fichero estuviera excluído o perteneciera a un subdirectorio dejamos que sea la herramienta, con su configuración, la que excluya el fichero.
     *
     * @return array. Cada elemento es la instancia de un objeto Tool distinto.
     */
    public function getTools(ConfigurationFile $configurationFile): array
    {
        $tools = [];
        foreach ($configurationFile->getToolsConfiguration() as $tool) {
            if (!in_array($tool->getTool(), self::ACCELERABLE_TOOLS)) {
                $tools[] = $tool;
                continue;
            }

            $originalPaths = $tool->getPaths();
            $modifiedFiles = $this->fileUtils->getModifiedFiles();

            $paths = $this->addFilesToToolPaths($modifiedFiles, $originalPaths);

            if (!empty($paths)) {
                $tool->setPaths($paths);
                $tools[] = $tool;
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
