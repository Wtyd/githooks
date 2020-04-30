<?php

namespace GitHooks\Tools;

use GitHooks\Constants;

/**
 * Ejecuta la libreria phpmd/phpmd
 */
class MessDetector extends ToolAbstract
{
    /**
     * @var string RULES Tag que indica la ruta del fichero de reglas que phpmd validará en el fichero de configuracion .yml
     */
    public const RULES = 'rules';

    /**
     * @var string EXCLUDE Tag que indica los directorios que phpmd excluirá del análisis en el fichero de configuracion .yml. Su valor es un array de strings.
     */
    public const EXCLUDE = 'exclude';

    /**
     * @var string PATH Tag que indica la ruta sobre la que se ejecutará phpmd en el fichero de configuracion .yml
     */
    public const PATHS = 'paths';

    public const OPTIONS = [self::RULES, self::EXCLUDE, self::PATHS];

    /**
     * @var array
     */
    protected $args;

    public function __construct(array $configurationFile)
    {
        $this->installer = 'phpmd/phpmd';

        $this->executable = 'phpmd';

        $this->setArguments($configurationFile);

        parent::__construct();
    }

    protected function prepareCommand(): string
    {
        $rules = '';
        if (!empty($this->args[self::RULES])) {
            $rules = $this->args[self::RULES];
        }

        $exclude = '';
        if (!empty($this->args[self::EXCLUDE])) {
            $exclude = '--exclude "' . implode(',', $this->args[self::EXCLUDE]) . '"';
        }

        $path = ''; // If path is empty phpmd will not work
        if (!empty($this->args[self::PATHS])) {
            $path = implode(',', $this->args[self::PATHS]);
        }

        $arguments = " $path text $rules $exclude";

        //text ./qa/md-rulesheet.xml --exclude "vendor,tests,views"
        return $this->executable . $arguments;
    }

    /**
     * Lee los argumentos y los setea.
     *
     * @param array $configurationFile
     * @return void
     */
    public function setArguments($configurationFile)
    {
        if (!isset($configurationFile[Constants::MESS_DETECTOR]) || empty($configurationFile[Constants::MESS_DETECTOR])) {
            return;
        }

        $arguments = $configurationFile[Constants::MESS_DETECTOR];

        if (!empty($arguments[self::RULES])) {
            $this->args[self::RULES] = $arguments[self::RULES];
        }

        if (!empty($arguments[self::EXCLUDE])) {
            $this->args[self::EXCLUDE] = $this->routeCorrector($arguments[self::EXCLUDE]);
        }

        if (!empty($arguments[self::PATHS])) {
            $this->args[self::PATHS] = $this->routeCorrector($arguments[self::PATHS]);
        }
    }
}
