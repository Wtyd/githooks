<?php

namespace GitHooks\Tools;

use GitHooks\Constants;

/**
 * Ejecuta la libreria sebastian/phpcpd
 */
class CopyPasteDetector extends ToolAbstract
{
    /**
     * @var string EXCLUDE Tag que indica los ficheros excluidos para phpcs en el fichero de configuracion .yml. Su valor es un array de strings.
     */
    public const EXCLUDE = 'exclude';

    /**
     * @var string PATH Tag que indica los ficheros sobre los que se ejecutará phpcpd en el fichero de configuracion .yml
     */
    public const PATH = 'path';

    public const OPTIONS = [self::EXCLUDE, self::PATH];

    /**
     * @var array
     */
    protected $args;

    public function __construct(array $configurationFile)
    {
        $this->installer = 'sebastian/phpcpd';

        $this->executable = 'phpcpd';

        $this->setArguments($configurationFile);

        parent::__construct();
    }

    protected function prepareCommand(): string
    {
        $exclude = '';
        if (!empty($this->args[self::EXCLUDE])) {
            $prefix = $this->addPrefixToArray($this->args[self::EXCLUDE], '--exclude ');
            $exclude = implode(' ', $prefix);
        }

        $path = ''; // If path is empty phpmd will not work
        if (!empty($this->args[self::PATH])) {
            $path = $this->args[self::PATH];
        }

        $arguments = "$exclude $path";

        return $this->executable . ' ' . $arguments;
    }

    /**
     * Lee los argumentos y los setea. Si vienen vacios se establecen unos por defecto.
     *
     * @param array $configurationFile
     * @return void
     */
    public function setArguments($configurationFile)
    {
        //$defaultExclude = ['app-front', 'database', 'tests', 'storage', 'vendor'];

        if (!isset($configurationFile[Constants::COPYPASTE_DETECTOR]) || empty($configurationFile[Constants::COPYPASTE_DETECTOR])) {
            return;
        }
        $arguments = $configurationFile[Constants::COPYPASTE_DETECTOR];

        if (!empty($arguments[self::EXCLUDE])) {
            $this->args[self::EXCLUDE] = $arguments[self::EXCLUDE];
        }

        if (!empty($arguments[self::PATH])) {
            $this->args[self::PATH] = $this->routeCorrectorString($arguments[self::PATH]);
        }
    }
}
