<?php

namespace GitHooks\Exception;

/**
 * Exception launched when some tool detects errors.
 */
class ExitException extends \RuntimeException implements GitHooksExceptionInterface
{
    public function __construct()
    {
        $this->message = 'Some tool has found errors';
    }
}
