<?php

namespace GitHooks\LoadTools;

use GitHooks\Constants;
use GitHooks\Tools\ToolsFactoy;
use GitHooks\Utils\GitFiles;

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

    /**
     * Configuration file 'githooks.yml' in array format. It could be like this:
     * ['Options' => ['execution' => 'smart], 'Tools' => ['parallel-lint', 'phpcs'], 'phpcs' => ['excludes' => ['vendor', 'qa'], 'rules' => 'rules_path.xml']];
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
            if ($this->isFile($exclude) && $file === $exclude) {
                return true;
            }

            if ($this->isSubstring($file, $exclude)) {
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
    protected function isSubstring($file, $exclude): bool
    {
        if (is_int(strpos($file, $exclude))) { //exclude es un substring de $file
            return true;
        }

        return false;
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
     * Busca la key de la $tool en el array $configuracion
     *
     * @param string $tool. Puede tomar los valores de las Constants como por ejemplo CODE_SNIFFER.
     *                      Se comprueba que la key $tools este en la raíz del array de configuración.
     * @return boolean
     */
    protected function toolHasConfiguration(string $tool): bool
    {
        if (array_key_exists($tool, $this->configurationFile)) {
            return true;
        }

        return false;
    }
}
