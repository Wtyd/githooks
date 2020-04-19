<?php

namespace GitHooks\Exception;

class ExitException extends \RuntimeException implements GitHooksExceptionInterface
{
    //TODO revisar esta exepcion
    public static function forException(\Throwable $exception): \Throwable
    {
        return $exception;
    }
}
