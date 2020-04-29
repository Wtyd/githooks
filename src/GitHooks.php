<?php

namespace GitHooks;

use GitHooks\Exception\ExitException;
use GitHooks\Exception\GitHooksExceptionInterface;
use GitHooks\LoadTools\Exception\LoadToolsExceptionInterface;
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

    public function __construct(string $configFile, Configuration $config, ChooseStrategy $chooseStrategy, Printer $printer, ToolExecutor $toolExecutor)
    {
        $this->printer = $printer;
        $this->toolExecutor = $toolExecutor;
        try {
            $file = $config->readfile($configFile);

            $strategy = $chooseStrategy->__invoke($file);

            $this->tools = $strategy->getTools();
        } catch (GitHooksExceptionInterface $ex) {
            $this->printer->generalFail($ex->getMessage());
            throw ExitException::forException($ex);
        } catch (LoadToolsExceptionInterface $ex) {
            $this->printer->generalFail($ex->getMessage());
            throw ExitException::forException($ex);
        }
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
        $executionTotalTime = ($endTotalTime - $startTotalTime);
        $this->printer->info("\n\n  Tiempo total de ejecución = " . number_format($executionTotalTime, 2) . " sec");

        if ($exitCode === self::OK) {
            $message = "Tus cambios se han commiteado.";
            $this->printer->success($message);
            echo "\n";
        } else {
            $this->printer->generalFail('Tus cambios no se han commiteado. Por favor, corrige los errores y vuelve a intentarlo.');
            echo "\n";
            throw new Exception("Cambios no commiteados");
        }
    }
}
