<?php

namespace GitHooks\Exception;

class ExitException extends \RuntimeException implements GitHooksExceptionInterface
{
    protected $exception;

    public static function forException(\Throwable $exception)
    {
        return $exception;
    }

    public function getException()
    {
        return $this->exception;
    }
}
