<?php
namespace GitHooks\Tools;

use GitHooks\Tools\Exception\ExitErrorException;
use GitHooks\Tools\Exception\ModifiedButUnstagedFilesException;
use GitHooks\Utils\Printer;

class ToolExecutor
{
    const OK = 0;

    const KO = 1;

    protected $printer;

    public function __construct(Printer $printer)
    {
        $this->printer = $printer;
    }

    /**
     * Ejecuta las herramientas y muestra un mensaje de OK o KO según el análisis de la herramienta.
     *
     * @param array $tools
     * @param boolean $isLiveOutput Si es true ejecutará la herramienta mostrando la salida en tiempo real como si la ejecutaramos manualmente por consola.
     *                  Si es false la ejecución de la herramienta no muestra ninguna.
     * @return integer $exitCode El codigo de salida (por defecto 0) cambia a 1 cuando una herrmienta falla por cualquier motivo
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    public function __invoke(array $tools, $isLiveOutput=false) :int
    {
        $exitCode = self::OK;
        foreach ($tools as $tool) {
            $startToolTime = microtime(true);
            try {
                if ($this->errorsFindingExecutable($tool->getErrors())) {
                    $this->printer->generalFail($tool->getErrors());
                    return self::KO;
                }

                if ($isLiveOutput) {
                    $tool->executeWithLiveOutput();
                } else {
                    $tool->execute();
                }

                $endToolTime = microtime(true);
                $executionToolTime = ($endToolTime - $startToolTime);

                if ($tool->getExitCode() === self::OK) {
                    $this->printer->success($tool->getExecutable(), $executionToolTime);
                } else {
                    $exitCode = self::KO;
                    $this->printer->fail($tool, $executionToolTime);
                }
            } catch (ModifiedButUnstagedFilesException $ex) {
                $endToolTime = microtime(true);
                $executionToolTime = ($endToolTime - $startToolTime);
                //TODO cambiar $tool->getExecutable() por el nombre de la herramienta para que aparezcan cosas como var/www/html/distribucion/vendor/zataca/githooks/src/Tools/../../../bin/phpcbf - OK. Time: 8.07
                $exitCode = self::KO;
                $message = $tool->getExecutable() . ' - OK. Time: ' . number_format($executionToolTime, 2) . '. Se han modificado algunos ficheros. Por favor, añádelos al stage y vuelve a commitear.';
                $this->printer->messageWarning($message);
            } catch (ExitErrorException $th) {
                //TODO a lo mejor cuando revienta una herramienta queremos mostrar el stacktraces para poder corregir la configuración de la herramienta. Esto viene de PHPStan
                $endToolTime = microtime(true);
                $executionToolTime = ($endToolTime - $startToolTime);
                $exitCode = self::KO;
                $this->printer->fail($tool, $executionToolTime);
            } catch (\Throwable $th) {
                $exitCode = self::KO;
                $this->printer->executionFail($tool->getExecutable(), $th->getMessage());
            }
        }

        return $exitCode;
    }

    protected function errorsFindingExecutable(string $errors)
    {
        if (empty($errors)) {
            return false;
        }

        return true;
    }
}
