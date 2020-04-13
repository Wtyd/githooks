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

    public const OPTIONS = [self::EXCLUDE];

    protected $args;

    public function __construct($configurationFile)
    {
        $this->installer = 'sebastian/phpcpd';

        $this->executable = 'phpcpd';

        $this->setArguments($configurationFile);

        parent::__construct();
    }

    protected function prepareCommand(): string
    {
        $prefix = $this->addPrefixToArray($this->args[self::EXCLUDE], '--exclude ');

        $exclude = implode(' ', $prefix);

        $arguments = "$exclude ./";

        return $this->executable . ' ' . $arguments;
    }

    /**
     * Lee los argumentos y los setea. Si vienen vacios se establecen unos por defecto.
     *
     * @param array $arguments
     * @return void
     */
    public function setArguments($configurationFile)
    {
        $defaultExclude = ['app-front', 'database', 'tests', 'storage', 'vendor'];

        if (!isset($configurationFile[Constants::COPYPASTE_DETECTOR]) || empty($configurationFile[Constants::COPYPASTE_DETECTOR])) {
            $this->args = [self::EXCLUDE => $defaultExclude];
            return;
        }

        $arguments = $configurationFile[Constants::COPYPASTE_DETECTOR];

        if (empty($arguments[self::EXCLUDE])) {
            $this->args[self::EXCLUDE] = $defaultExclude;
        } else {
            $this->args[self::EXCLUDE] = $arguments[self::EXCLUDE];
        }
    }
}
