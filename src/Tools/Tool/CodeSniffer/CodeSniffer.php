<?php

namespace Wtyd\GitHooks\Tools\Tool\CodeSniffer;

use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;
use Wtyd\GitHooks\Tools\ToolAbstract;

/**
 * Library squizlabs/php_codesniffer
 */
abstract class CodeSniffer extends ToolAbstract
{
    /**
     * @var string PATHS Tag que indica sobre qué carpetas se debe ejecutar el análisis de phpstan en el fichero de configuracion .yml
     */
    public const PATHS = 'paths';

    /**
     * @var string STANDARD Tag que indica la ruta del fichero de configuración de phpcs-rulesheet.xml en el fichero de configuracion .yml
     */
    public const STANDARD = 'standard';

    /**
     * @var string IGNORE Tag que indica los ficheros excluidos para phpcs en el fichero de configuracion .yml. Su valor es un array de strings.
     */
    public const IGNORE = 'ignore';

    /**
     * @var string ERROR_SEVERITY Tag que indica la sensibilidad a errores de phpcs en el fichero de configuracion .yml. Su valor es un entero de 1 a 7.
     */
    public const ERROR_SEVERITY = 'error-severity';

    /**
     * @var string WARNING_SEVERITY Tag que indica la sensibilidad a warnings de phpcs en el fichero de configuracion .yml. Su valor es un entero de 1 a 7.
     */
    public const WARNING_SEVERITY = 'warning-severity';

    public const OPTIONS = [self::PATHS, self::STANDARD, self::IGNORE, self::ERROR_SEVERITY, self::WARNING_SEVERITY];


    protected function prepareCommand(): string
    {
        $arguments = '';
        foreach (self::OPTIONS as $option) {
            if (empty($this->args[$option])) {
                continue;
            }
            if (self::PATHS === $option) {
                $arguments .= implode(' ', $this->args[$option]) . ' ';
            } elseif (self::IGNORE === $option) {
                $arguments .= "--$option=" . implode(',', $this->args[$option]) . ' ';
            } else {
                $arguments .= "--$option=" . $this->args[$option] . ' ';
            }
        }

        //phpcs src --standard=./qa/psr12-ruleset.xml --ignore=vendor,otrodir --error-severity=1 --warning-severity=6
        return $this->executablePath . ' ' . $arguments;
    }

    public function setArguments(array $configurationFile): void
    {
        $this->executablePath = $this->routeCorrector($configurationFile[self::EXECUTABLE_PATH_OPTION] ?? 'phpcs');

        if (!empty($configurationFile[self::PATHS])) {
            $this->args[self::PATHS] = $this->multipleRoutesCorrector($configurationFile[self::PATHS]);
        }

        if (!empty($configurationFile[self::STANDARD])) {
            $this->args[self::STANDARD] = $configurationFile[self::STANDARD];
        }

        if (!empty($configurationFile[self::IGNORE])) {
            $this->args[self::IGNORE] = $this->multipleRoutesCorrector($configurationFile[self::IGNORE]);
        }

        if (!empty($configurationFile[self::ERROR_SEVERITY])) {
            $this->args[self::ERROR_SEVERITY] = $configurationFile[self::ERROR_SEVERITY];
        }

        if (!empty($configurationFile[self::WARNING_SEVERITY])) {
            $this->args[self::WARNING_SEVERITY] = $configurationFile[self::WARNING_SEVERITY];
        }
    }
}
