<?php

namespace Wtyd\GitHooks\Tools;

class Errors
{
    /**
     * @var array
     */
    protected $errors = [];

    public function setError(string $tool, string $error): void
    {
        if (!empty($tool)) {
            $this->errors[$tool] = !empty($error) ? $error :  'register error in live output execution';
        }
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function isEmpty(): bool
    {
        return empty($this->errors);
    }

    public function __toString()
    {

        if (empty($this->errors)) {
            return 'There are no errors.';
        } else {
            $message = 'The following errors have occurred:';
            foreach ($this->errors as $key => $error) {
                $message .= "\nFor $key:\n $error";
            }
            return $message;
        }
    }
}
