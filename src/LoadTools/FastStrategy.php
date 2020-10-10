<?php

namespace GitHooks\LoadTools;

use GitHooks\Constants;
use GitHooks\Tools\ToolsFactoy;
use GitHooks\Utils\GitFiles;

/**
 * This strategy runs the tools only against files modified by commit.
 * 1. This option only affects the following tools: phpcs, phpmd, phpstan, and parallel-lint (ACCELERABLE_TOOLS). The rest of the tools will run as the full
 * option.
 * 2. WARNING!!! You must set the excludes of the tools either in githooks.yml or in the configuration file of eath tool since this option overwrites the key
 * paths of the tools so that they are executed only against the modified files.
 */
class FastStrategy implements StrategyInterface
{

    /**
     * Tools which execution can be improve by FastStrategy. The following tools are not affected:
     * 1. Check-Security no executes against any path.
     * 2. Copy Paste Detector needs all files to be able to compare
     */
    public const ACCELERABLE_TOOLS = [
        Constants::CODE_SNIFFER,
        Constants::PARALLEL_LINT,
        Constants::MESS_DETECTOR,
        Constants::PHPSTAN,
    ];

    /**
     * Todo el fichero de configuración pasado a array. Su formato podria ser algo como lo siguiente:
     * ['Options' => ['execution' => 'fast], 'Tools' => ['parallel-lint', 'phpcs'], 'phpcs' => ['excludes' => ['vendor', 'qa'], 'rules' => 'rules_path.xml']];
     *
     * @var array
     */
    protected $configurationFile;

    /**
     * @var GitFiles
     */
    protected $gitFiles;

    /**
     * @var ToolsFactoy
     */
    protected $toolsFactory;

    public function __construct(array $configurationFile, GitFiles $gitFiles, ToolsFactoy $toolsFactory)
    {
        $this->configurationFile = $configurationFile;
        $this->gitFiles = $gitFiles;
        $this->toolsFactory = $toolsFactory;
    }

    /**
     * Se cargan todas las herramientas configuradas como en la FullStrategy. La diferencia es que editamos en el fichero de configuración los 'paths'
     * contra los que se ejecutan las herramientas. Ahora, en lugar de contra directorios 'pahts' se ejecuta contra los ficheros modificados que pertenezcan
     * a esos 'paths' o a sus subdirectorios.
     * Si un fichero estuviera excluído o perteneciera a un subdirectorio dejamos que sea la herramienta, con su configuración, la que excluya el fichero.
     *
     * @return array. Cada elemento es la instancia de un objeto Tool distinto.
     */
    public function getTools(): array
    {

        $tools = [];
        foreach ($this->configurationFile[Constants::TOOLS] as $tool) {
            if (!in_array($tool, self::ACCELERABLE_TOOLS)) {
                $tools[] = $tool;
                continue;
            }

            $originalPaths = $this->configurationFile[$tool][Constants::TOOL_LIST[$tool]::PATHS];
            $modifiedFiles = $this->gitFiles->getModifiedFiles();

            $paths = $this->addFilesToPaths($modifiedFiles, $originalPaths);

            if (!empty($paths)) {
                $this->configurationFile[$tool][Constants::TOOL_LIST[$tool]::PATHS] = $paths;
                $tools[] = $tool;
            }
        }

        return $this->toolsFactory->__invoke($tools, $this->configurationFile);
    }

    protected function addFilesToPaths(array $modifiedFiles, array $originalPaths): array
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
            if ($this->isFile($path) && $file === $path) {
                return true;
            }

            if ($this->isSubstring($file, $path)) {
                return true;
            }

            continue;
        }

        return false;
    }

    protected function isFile(string $filePath): bool
    {
        if ('php' ===  pathinfo($filePath, PATHINFO_EXTENSION)) {
            return true;
        }
        return false;
    }

    /**
     * Verifica que $exclude sea un substring de $file
     *
     * @param string $file. Ruta de un fichero. Pj, 'app/Controllers/MiController.php'.
     * @param string $exclude. Ruta excluida. Pj, 'app'.
     * @return boolean
     */
    protected function isSubstring(string $file, string $exclude): bool
    {
        if (is_int(strpos($file, $exclude))) { //exclude es un substring de $file
            return true;
        }

        return false;
    }
}
