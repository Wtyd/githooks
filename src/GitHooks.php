<?php

namespace GitHooks;

use GitHooks\Exception\ExitException;
use GitHooks\Tools\ToolExecutor;
use Exception;
use GitHooks\Utils\Printer;

/**
 * @SuppressWarnings(PHPMD.ExitExpression)
 */
class GitHooks
{
    public const OK = 0;

    public const KO = 1;

    /**
     * @var array de Tools (AbstractTools)
     */
    protected $tools;

    /**
     * @var Printer
     */
    protected $printer;

    /**
     * @var ToolExecutor
     */
    protected $toolExecutor;

    public function __construct(Configuration $config, ChooseStrategy $chooseStrategy, Printer $printer, ToolExecutor $toolExecutor)
    {
        $this->printer = $printer;
        $this->toolExecutor = $toolExecutor;

        $file = $config->readfile();

        $strategy = $chooseStrategy->__invoke($file);

        $this->tools = $strategy->getTools();
    }

    /**
     * Método principal de la herramienta. Ejecuta todas las herramientas establecidas en el array TOOLS.
     * Permite commitear cuando las herramientas se ejecutan correctamente. Da error y no permite commitear en los siguientes casos:
     * 1. No encuentra el ejecutable de alguna de las herramientas (busca en .phar, global y local)
     * 2. Las herramientas pasan pero Code Sniffer ha corregido algunos ficheros. Se deben añadir al commit y volver a commitear.
     * 3. Cualquiera de las herramientas tiene una salida inesperada.
     *
     * @return void
     */
    public function __invoke()
    {
        $startTotalTime = microtime(true);
        $exitCode = $this->toolExecutor->__invoke($this->tools);

        $endTotalTime = microtime(true);
        $executionTotalTime = $endTotalTime - $startTotalTime;
        $this->printer->line("\n  Total run time = " . number_format($executionTotalTime, 2) . " seconds.");

        if ($exitCode === self::OK) {
            $message = 'Your changes have been committed.';
            $this->printer->success($message);
        } else {
            $this->printer->generalFail('Your changes have not been committed. Please fix the errors and try again.');
            throw new ExitException();
        }
    }
}
