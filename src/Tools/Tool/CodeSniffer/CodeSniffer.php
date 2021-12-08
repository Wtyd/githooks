<?php

namespace Wtyd\GitHooks\Tools\Tool\CodeSniffer;

use Wtyd\GitHooks\Tools\Tool\ToolAbstract;

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

    public const OPTIONS = [
        self::EXECUTABLE_PATH_OPTION,
        self::PATHS,
        self::STANDARD,
        self::IGNORE,
        self::ERROR_SEVERITY,
        self::WARNING_SEVERITY,
        self::OTHER_ARGS_OPTION,
    ];


    protected function prepareCommand(): string
    {
        $command = '';
        foreach (self::OPTIONS as $option) {
            if (empty($this->args[$option])) {
                continue;
            }

            switch ($option) {
                case self::EXECUTABLE_PATH_OPTION:
                    $command = $this->args[self::EXECUTABLE_PATH_OPTION];
                    break;
                case self::PATHS:
                    $command .= ' ' . implode(' ', $this->args[$option]);
                    break;
                case self::STANDARD:
                case self::ERROR_SEVERITY:
                case self::WARNING_SEVERITY:
                    $command .= " --$option=" . $this->args[$option];
                    break;
                case self::IGNORE:
                    $command .= " --$option=" . implode(',', $this->args[$option]);
                    break;
                default:
                    $command .= ' ' . $this->args[self::OTHER_ARGS_OPTION];
                    break;
            }
        }

        //args = '--report=full'
        //phpcs src --standard=./qa/psr12-ruleset.xml --ignore=vendor,otrodir --error-severity=1 --warning-severity=6 --report=full
        // dd($command);
        return $command;
    }
}
