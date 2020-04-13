<?php

namespace GitHooks\Tools;

use GitHooks\Constants;

/**
 * Ejecuta la libreria jakub-onderka/php-parallel-lint
 */
class ParallelLint extends ToolAbstract
{
    /**
     * @var string EXCLUDES Tag de configuracion de directorios excluidos en el fichero de configuracion .yml. Su valor es un array de strings.
     */
    public const EXCLUDE = 'exclude';

    public const OPTIONS = [self::EXCLUDE];

    protected $excludes;

    public function __construct($configurationFile)
    {
        $this->installer = 'php-parallel-lint/php-parallel-lint';

        $this->executable = Constants::PARALLEL_LINT;

        $this->setArguments($configurationFile);

        parent::__construct();
    }

    protected function prepareCommand(): string
    {
        $prefix = $this->addPrefixToArray($this->excludes, '--exclude ');

        $exclude = implode(' ', $prefix);

        $arguments = ' ' . $this->path() . ' ' . $exclude;

        //parallel-lint ./ --exclude qa --exclude tests --exclude vendor
        return $this->executable . $arguments;
    }

    /**
     * Sirve para poder doblar el sistema de ficheros en las pruebas
     *
     * @return string
     */
    public function path()
    {
        return './';
    }

    /**
     * Lee los argumentos y los setea. Si vienen vacios se establecen unos por defecto.
     *
     * @param array $arguments
     * @return void
     */
    public function setArguments($configurationFile)
    {
        if (!isset($configurationFile[Constants::PARALLEL_LINT]) || empty($configurationFile[Constants::PARALLEL_LINT])) {
            $this->excludes = ['qa','docker','vendor'];
            return;
        }

        $arguments = $configurationFile[Constants::PARALLEL_LINT];

        if (empty($arguments[self::EXCLUDE])) {
            $this->excludes = ['qa','docker','vendor'];
        } else {
            $this->excludes = $this->routeCorrector($arguments[self::EXCLUDE]);
        }
    }
}
