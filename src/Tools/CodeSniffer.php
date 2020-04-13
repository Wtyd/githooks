<?php

namespace GitHooks\Tools;

use GitHooks\Constants;
use GitHooks\Tools\Exception\ModifiedButUnstagedFilesException;

/**
 * Ejecuta la libreria squizlabs/php_codesniffer
 */
class CodeSniffer extends ToolAbstract
{
    /**
     * @var string STANDARD Tag que indica la ruta del fichero de configuración de phpcs-rulesheet.xml en el fichero de configuracion .yml
     */
    const STANDARD = 'standard';

    /**
     * @var string IGNORE Tag que indica los ficheros excluidos para phpcs en el fichero de configuracion .yml. Su valor es un array de strings.
     */
    const IGNORE = 'ignore';

    /**
    * @var string ERROR_SEVERITY Tag que indica la sensibilidad a errores de phpcs en el fichero de configuracion .yml. Su valor es un entero de 1 a 7.
    */
    const ERROR_SEVERITY = 'error-severity';

    /**
    * @var string WARNING_SEVERITY Tag que indica la sensibilidad a warnings de phpcs en el fichero de configuracion .yml. Su valor es un entero de 1 a 7.
    */
    const WARNING_SEVERITY = 'warning-severity';

    const OPTIONS = [self::STANDARD, self::IGNORE, self::ERROR_SEVERITY, self::WARNING_SEVERITY];

    protected $args;

    public function __construct($configurationFile)
    {
        $this->installer = 'squizlabs/php_codesniffer';

        $this->executable = 'phpcbf';

        $this->setArguments($configurationFile);

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
        $phpcbf = 'phpcbf ' . $this->prepareCommand();

        $exitBF = $exitCodeBF = null;
        exec($phpcbf, $exitBF, $exitCodeBF);

        if (0 === $exitCodeBF) {
            $this->exit = $exitBF;
        } else {
            $phpcs = 'phpcs ' . $this->prepareCommand();

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
        $standard = '--standard=' . $this->args[self::STANDARD];
        $excludes = '--ignore=' . implode(',', $this->args[self::IGNORE]);//*/build/*,*/database/*,*/qa/*,*/node_modules/*,*/storage/*,*/tests/*,
        $errorSeverity = '--error-severity=' . $this->args[self::ERROR_SEVERITY];
        $warningSeverity = '--warning-severity=' . $this->args[self::WARNING_SEVERITY];

        $arguments = "./  --colors $standard $excludes $errorSeverity $warningSeverity";

        return $arguments;
    }

    /**
     * Sobreescritura del método padre.
     *
     * @return void
     */
    public function executeWithLiveOutput()
    {
        $command = 'phpcbf ' . $this->prepareCommand();
        echo "$command\n";
        passthru($command, $this->exitCode);
    }

    /**
     * Lee los argumentos y los setea. Si vienen vacios se establecen unos por defecto.
     *
     * @param array $arguments
     * @return void
     */
    public function setArguments($configurationFile)
    {
        $defaultStandard = 'qa/phpcs-softruleset.xml';
        $defaultIgnore = ['app-front','build', 'database', 'node_modules', 'storage', 'vendor'];
        $defaultErrorSeverity = 1;
        $defaultWarningSeverity = 6;

        if (!isset($configurationFile[Constants::CODE_SNIFFER]) || empty($configurationFile[Constants::CODE_SNIFFER])) {
            $this->args = [
                self::STANDARD => $defaultStandard,
                self::IGNORE => $defaultIgnore,
            ];
            return;
        }

        $arguments = $configurationFile[Constants::CODE_SNIFFER];

        if (empty($arguments[self::STANDARD])) {
            $this->args[self::STANDARD] = $defaultStandard;
        } else {
            $this->args[self::STANDARD] = $arguments[self::STANDARD];
        }

        if (empty($arguments[self::IGNORE])) {
            $this->args[self::IGNORE] = $defaultIgnore;
        } else {
            $this->args[self::IGNORE] = $this->routeCorrector($arguments[self::IGNORE]);
        }

        if (empty($arguments[self::ERROR_SEVERITY])) {
            $this->args[self::ERROR_SEVERITY] = $defaultErrorSeverity;
        } else {
            $this->args[self::ERROR_SEVERITY] = $arguments[self::ERROR_SEVERITY];
        }

        if (empty($arguments[self::WARNING_SEVERITY])) {
            $this->args[self::WARNING_SEVERITY] = $defaultWarningSeverity;
        } else {
            $this->args[self::WARNING_SEVERITY] = $arguments[self::WARNING_SEVERITY];
        }
    }

    public function checkConfiguration(array $configuration): array
    {
        $configuration = $configuration[Constants::CODE_SNIFFER];
        $expectedValues = [self::ERROR_SEVERITY, self::WARNING_SEVERITY, self::IGNORE, self::STANDARD];
        $errors = [];
        $warnings = [];

        foreach (array_keys($configuration) as $key) {
            if (! in_array($key, $expectedValues)) {
                $errors[] = "El elemento $key no es válido para la herramienta " . Constants::CODE_SNIFFER;
            }
        }

        return [Constants::ERRORS => $errors, Constants::WARNINGS => $warnings];
    }
}
