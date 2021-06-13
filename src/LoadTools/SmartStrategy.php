<?php

namespace Wtyd\GitHooks\LoadTools;

use Wtyd\GitHooks\Constants;
use Wtyd\GitHooks\Tools\ToolsFactoy;
use Wtyd\GitHooks\Utils\GitFilesInterface;
use Storage;

/**
 * This strategy tries to save execution time when running the application. For this, it may not execute any of the configured tools if all the commit files do
 * not belong to any of the directories against which the tool is launched or are files that are in excluded or ignored directories.
 * For example: we modify a test and phpmd has the tests folder excluded. In this case, phpmd will not be executed.
 * - The tools this strategy affects are: phpcs, phpmd, phpcpd, and parallel-lint. That is, they may not be executed even if they are configured.
 * - The tools that are NOT affected by this strategy are: phpstan (you can only mark exclusions in its configuration file) and security-check. These tools will
 * run as long as they are configured even if the smart option is active.
 */
class SmartStrategy implements StrategyInterface
{
    public const ROOT_PATH = './';

    /**
     * Configuration file 'githooks.yml' in array format. It could be like this:
     * ['Options' => ['execution' => 'smart], 'Tools' => ['parallel-lint', 'phpcs'], 'phpcs' => ['excludes' => ['vendor', 'qa'], 'rules' => 'rules_path.xml']];
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
     * Se cargan únicamente las herramientas si hay ficheros modificados que no están en sus carpetas de exclusión. Es decir, si modificamos
     * un fichero dentro de config/ y phpcpd tiene esa carpeta en exclusión, no se cargará y por tanto no se ejecutará.
     *
     * @return array. Cada elemento es la instancia de un objeto Tool distinto.
     */
    public function getTools(): array
    {
        $tools = [];
        foreach ($this->configurationFile[Constants::TOOLS] as $tool) {
            if ($this->toolShouldSkip($tool)) {
                continue;
            }

            $tools[] = $tool;
        }

        return $this->toolsFactory->__invoke($tools, $this->configurationFile);
    }

    /**
     * Para saltar la herramienta se deben cumplir todas las condiciones.
     * 1. La herramienta debe tener configurada rutas de exclusión/ignore.
     * 2. TODOS los ficheros modificados deben pertenecer a rutas de exclusión de la herramienta.
     *
     * @param string $tool
     * @return bool
     */
    protected function toolShouldSkip(string $tool): bool
    {
        $toolShouldSkip = false;
        if (Constants::CHECK_SECURITY !== $tool) {
            $toolShouldSkip = $this->toolHasExclusionsConfigured($tool) && $this->allModifiedFilesAreExcluded($tool);
        }
        return $toolShouldSkip;
    }

    /**
     * La verifica que la $tool tenga su apartado de configuración y en el su apartado de excludes/ignores definido
     *
     * @param string $tool
     * @return bool
     */
    protected function toolHasExclusionsConfigured(string $tool): bool
    {
        $exclusionConfigured = false;
        try {
            if (isset($this->configurationFile[$tool]) && array_key_exists(Constants::EXCLUDE_ARGUMENT[$tool], $this->configurationFile[$tool])) {
                $exclusionConfigured = true;
            }
        } catch (\Throwable $th) {
            $exclusionConfigured = false;
        }

        return $exclusionConfigured;
    }

    /**
     * Comprueba que todos los ficheros modificados están excluidos del análisis de la herramienta
     *
     * @param string $tool. Nombre de la herramienta
     * @return boolean. True cuando TODOS los ficheros modificados están excluídos. False cuando al menos un fichero modificado no está excluído.
     */
    protected function allModifiedFilesAreExcluded(string $tool): bool
    {
        $excludes = $this->configurationFile[$tool][Constants::EXCLUDE_ARGUMENT[$tool]];

        $modifiedFiles = $this->gitFiles->getModifiedFiles();

        foreach ($modifiedFiles as $file) {
            if (!$this->isFileExcluded($file, $excludes)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Verifica si $file pertenece a algún direcotorio o subdirectorio de los directorios excluidos.
     *
     * @param string $file. El fichero modificado.
     * @param array $excludes. Directorios o ficheros excluidos.
     * @return boolean
     */
    protected function isFileExcluded(string $file, array $excludes): bool
    {
        foreach ($excludes as $exclude) {
            if (is_file($exclude) && $this->isSameFile($file, $exclude)) {
                return true;
            }

            if ($this->directoryContainsFile($exclude, $file)) {
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

    /**
     * If the $directory is root of work directory it is sure that the modified file is in $directory.
     *
     * @param string $directory
     * @param string $file
     * @return boolean
     */
    protected function directoryContainsFile(string $directory, string $file): bool
    {
        if ($directory === self::ROOT_PATH) {
            return true;
        }
        return in_array($file, Storage::allFiles($directory));
    }
}
