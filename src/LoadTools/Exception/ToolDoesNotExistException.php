<?php

namespace GitHooks\LoadTools\Exception;

class ToolDoesNotExistException extends \DomainException implements LoadToolsExceptionInterface
{
    /**
     * @var string Nombre de la herramienta
     */
    private $tool;

    //TODO Cuando intent instanciar una herramienta no contemplada (pj ponemos en el yaml phpmdnovale) además del mensaje muestra el stacktrace (lo cual es inencesario)
    public static function forTool(string $tool): ToolDoesNotExistException
    {
        $exception = new self(sprintf(
            'La herramienta %s no está contemplada por GiHooks',
            $tool
        ));

        $exception->tool = $tool;

        return $exception;
    }

    public function getTool(): string
    {
        return $this->tool;
    }
}
