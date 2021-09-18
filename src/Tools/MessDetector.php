<?php

namespace Wtyd\GitHooks\Tools;

use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;
use Wtyd\GitHooks\Constants;

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

    public function __construct(ToolConfiguration $toolConfiguration)
    {
        $this->executable = self::MESS_DETECTOR;

        $this->setArguments($toolConfiguration->getToolConfiguration());
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

        $arguments = " $path ansi $rules $exclude";

        // ./src/ ansi ./qa/phpmd-src-ruleset.xml --exclude "vendor"
        return $this->executablePath . $arguments;
    }

    public function setArguments(array $configurationFile): void
    {
        $this->executablePath = $this->routeCorrector($configurationFile[self::EXECUTABLE_PATH_OPTION] ?? self::MESS_DETECTOR);

        if (!empty($configurationFile[self::RULES])) {
            $this->args[self::RULES] = $configurationFile[self::RULES];
        }

        if (!empty($configurationFile[self::EXCLUDE])) {
            $this->args[self::EXCLUDE] = $this->multipleRoutesCorrector($configurationFile[self::EXCLUDE]);
        }

        if (!empty($configurationFile[self::PATHS])) {
            $this->args[self::PATHS] = $this->multipleRoutesCorrector($configurationFile[self::PATHS]);
        }
    }

    /**
     * Método donde se ejecuta la herramienta mediante exec. La herramienta no producirá ninguna salida.
     *
     * @return void
     */
    public function execute()
    {
        parent::execute();
        if ($this->exitCode == 0 && $this->isThereHiddenError()) {
            $this->exitCode = 1;
        }
    }

    /**
     * Check if the exit of Mess detector is OK.
     * If there is an error in the source file that prevents Mess detector from parsing it, Mess detector will return an exit code 0.
     * But mess detector will not be able to check that file.
     *
     * @return bool
     */
    protected function isThereHiddenError()
    {
        if (is_int(strpos($this->exit[3], 'No mess detected'))) {
            return false;
        }
        return true;
    }
}
