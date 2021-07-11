<?php

namespace Wtyd\GitHooks\Tools;

use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;
use Wtyd\GitHooks\Tools\Exception\ModifiedButUnstagedFilesException;

/**
 * Ejecuta la libreria squizlabs/php_codesniffer
 */
class CodeSniffer extends ToolAbstract
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


    /**
     * @var array
     */
    protected $args;

    public function __construct(ToolConfiguration $toolConfiguration)
    {
        $this->installer = 'squizlabs/php_codesniffer';

        $this->executable = 'phpcbf';

        $this->setArguments($toolConfiguration->getToolConfiguration());

        parent::__construct();
    }

    /**
     * Comprueba el formateado de código. Se realiza en dos pasos:
     * 1. Ejecuta beautifier (phpcbf) que puede corregir algunos errores de forma automática (saltos de línea, espacios en blanco, etc).
     * 2. Ejecuta code sniffer (phpcs) para comprobar que después de la corrección automática ya no quedan más errores.
     *
     * @return void
     */
    public function execute()
    {
        $phpcbf = $this->prepareCommand();

        $exitBF = $exitCodeBF = null;
        exec($phpcbf, $exitBF, $exitCodeBF);

        if (0 === $exitCodeBF) {
            $this->exit = $exitBF;
        } else {
            //change 'phpcbf args' by 'phpcs args'
            $phpcs = str_replace('phpcbf', 'phpcs', $this->prepareCommand());

            $exitCS = $exitCodeCS = null;
            exec($phpcs, $exitCS, $exitCodeCS);

            $this->exit = $exitCS;

            if (0 === $exitCodeCS) {
                throw ModifiedButUnstagedFilesException::forExit($exitCS);
            }
        }

        $this->exitCode = $exitCodeBF;
    }

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

        //phpcbf src --standard=./qa/psr12-ruleset.xml --ignore=vendor,otrodir --error-severity=1 --warning-severity=6
        return $this->executable . ' ' . $arguments;
    }

    /**
     * Lee los argumentos y los setea. Si vienen vacios se establecen unos por defecto.
     * //TODO este metodo creo que se puede eliminar entero ya que no hace falta validar nada
     *
     * @return void
     */
    public function setArguments(array $arguments): void
    {
        if (!empty($arguments[self::PATHS])) {
            $this->args[self::PATHS] = $this->routeCorrector($arguments[self::PATHS]);
        }

        if (!empty($arguments[self::STANDARD])) {
            $this->args[self::STANDARD] = $arguments[self::STANDARD];
        }

        if (!empty($arguments[self::IGNORE])) {
            $this->args[self::IGNORE] = $this->routeCorrector($arguments[self::IGNORE]);
        }

        if (!empty($arguments[self::ERROR_SEVERITY])) {
            $this->args[self::ERROR_SEVERITY] = $arguments[self::ERROR_SEVERITY];
        }

        if (!empty($arguments[self::WARNING_SEVERITY])) {
            $this->args[self::WARNING_SEVERITY] = $arguments[self::WARNING_SEVERITY];
        }
    }
}
