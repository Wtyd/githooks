<?php

namespace Wtyd\GitHooks\Tools;

use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;
use Wtyd\GitHooks\Constants;

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
    public const PATHS = 'paths';

    public const OPTIONS = [self::EXCLUDE, self::PATHS];

    /**
     * @var array
     */
    protected $args;

    //TODO add --names-exclude option. Is like --exclude but for files. Check 6.* interfaces because bring changes.
    public function __construct(ToolConfiguration $toolConfiguration)
    {
        $this->installer = 'sebastian/phpcpd';

        $this->executable = self::COPYPASTE_DETECTOR;

        $this->setArguments($toolConfiguration->getToolConfiguration());

        parent::__construct();
    }

    protected function prepareCommand(): string
    {
        $exclude = '';
        if (!empty($this->args[self::EXCLUDE])) {
            $prefix = $this->addPrefixToArray($this->args[self::EXCLUDE], '--exclude ');
            $exclude = implode(' ', $prefix);
        }

        $paths = ''; // If path is empty phpmd will not work
        if (!empty($this->args[self::PATHS])) {
            $paths = implode(' ', $this->args[self::PATHS]);
        }

        $arguments = "$exclude $paths";

        return $this->executable . ' ' . $arguments;
    }

    public function setArguments(array $configurationFile): void
    {
        if (!empty($configurationFile[self::EXCLUDE])) {
            $this->args[self::EXCLUDE] = $this->routeCorrector($configurationFile[self::EXCLUDE]);
        }

        if (!empty($configurationFile[self::PATHS])) {
            $this->args[self::PATHS] = $this->routeCorrector($configurationFile[self::PATHS]);
        }
    }
}
