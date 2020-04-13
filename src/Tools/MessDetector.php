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

    public const OPTIONS = [self::RULES, self::EXCLUDE];

    protected $args;

    public function __construct($configurationFile)
    {
        $this->installer = 'phpmd/phpmd';

        $this->executable = 'phpmd';

        $this->setArguments($configurationFile);

        parent::__construct();
    }

    protected function prepareCommand(): string
    {
        $rules = $this->args[self::RULES];

        $exclude = '--exclude "' . implode(',', $this->args[self::EXCLUDE]) . '"';

        $arguments = " ./  text $rules $exclude";

        //text ./qa/md-rulesheet.xml --exclude "vendor,tests,views"
        return $this->executable . $arguments;
    }

    /**
     * Lee los argumentos y los setea. Si vienen vacios se establecen unos por defecto.
     *
     * @param array $arguments
     * @return void
     */
    public function setArguments($configurationFile)
    {
        $defaultRules = './qa/md-rulesheet.xml';
        $defaultExclude = ['app-front','build', 'database', 'node_modules', 'storage', 'tests', 'vendor'];

        if (!isset($configurationFile[Constants::MESS_DETECTOR]) || empty($configurationFile[Constants::MESS_DETECTOR])) {
            $this->args = [
                self::RULES => $defaultRules,
                self::EXCLUDE => $defaultExclude,
            ];
            return;
        }

        $arguments = $configurationFile[Constants::MESS_DETECTOR];

        if (empty($arguments[self::RULES])) {
            $this->args[self::RULES] = $defaultRules;
        } else {
            $this->args[self::RULES] = $arguments[self::RULES];
        }

        if (empty($arguments[self::EXCLUDE])) {
            $this->args[self::EXCLUDE] = $defaultExclude;
        } else {
            $this->args[self::EXCLUDE] = $this->routeCorrector($arguments[self::EXCLUDE]);
        }
    }
}
