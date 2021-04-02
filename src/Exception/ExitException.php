<?php

namespace GitHooks\Exception;

use GitHooks\Tools\Errors;

/**
 * Exception launched when some tool detects errors.
 */
class ExitException extends \RuntimeException implements GitHooksExceptionInterface
{
    public static function forErrors(string $errors): ExitException
    {
        $exception = new self(
            $errors
        );

        return $exception;
    }

    /**
     * Convierte un array asociativo en un string con el formato 'key'='value'
     *
     * @param array $array a transformar.
     * @return string
     */
    protected function arrayToString(array $array)
    {
        return implode(', ', array_map(
            function ($value, $key) {
                return sprintf("'%s'='%s'", $key, $value);
            },
            $array,
            array_keys($array)
        ));
    }
}
