<?php

namespace Wtyd\GitHooks\LoadTools;

use Illuminate\Support\Facades\Storage as FacadesStorage;
use Wtyd\GitHooks\Constants;
use Wtyd\GitHooks\Tools\ToolsFactoy;
use Wtyd\GitHooks\Utils\GitFilesInterface;
// use Illuminate\Support\Facades\Storage;
use Storage;

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

    public const ROOT_PATH = './';

    /**
     * Todo el fichero de configuración pasado a array. Su formato podria ser algo como lo siguiente:
     * ['Options' => ['execution' => 'fast], 'Tools' => ['parallel-lint', 'phpcs'], 'phpcs' => ['excludes' => ['vendor', 'qa'], 'rules' => 'rules_path.xml']];
     *
     * @var array
     */
    protected $configurationFile;

    /**
     * @var GitFilesInterface
     */
    protected $gitFiles;

    /**
     * @var ToolsFactoy
     */
    protected $toolsFactory;

    public function __construct(array $configurationFile, GitFilesInterface $gitFiles, ToolsFactoy $toolsFactory)
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

            $paths = $this->addFilesToToolPaths($modifiedFiles, $originalPaths);

            if (!empty($paths)) {
                $this->configurationFile[$tool][Constants::TOOL_LIST[$tool]::PATHS] = $paths;
                $tools[] = $tool;
            }
        }

        return $this->toolsFactory->__invoke($tools, $this->configurationFile);
    }

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
            if (is_file($path) && $this->isSameFile($file, $path)) {
                return true;
            }

            if ($this->directoryContainsFile($path, $file)) {
                return true;
            }

            continue;
        }

        return false;
    }

    /**
     * Check if two files are the same file. The problem comes when the configuration file file is preceded by the string
     * ROOT_PATH.
     *
     * @param string $file1
     * @param string $file2
     * @return boolean
     */
    public function isSameFile($file1, $file2): bool
    {
        $file1 = explode(self::ROOT_PATH, $file1);
        $file1 = count($file1) > 1 ? $file1[1] : $file1[0];

        $file2 = explode(self::ROOT_PATH, $file2);
        $file2 = count($file2) > 1 ? $file2[1] : $file2[0];

        return $file1 === $file2;
    }

    protected function directoryContainsFile(string $directory, string $file): bool
    {
        // dd('directoryContainsFile');
        if ($directory === self::ROOT_PATH) {
            // $rootFiles = Storage::files($directory);
            // $rootDirectories = Storage::directories($directory);

            // //Remove vendor directory (node_modules??)
            // if (($key = array_search('vendor', $rootDirectories)) !== false) {
            //     unset($rootDirectories[$key]);
            // }
            return true;
        }
        return in_array($file, Storage::allFiles($directory));
    }

    /**
     * ya no se usa
     *
     * @param string $filePath
     * @return boolean
     */
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
