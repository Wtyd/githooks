<?php

namespace GitHooks\Tools;

class Errors
{
    protected $errors = [];
    public function setError(string $tool, string $error)
    {
        if (!empty($tool) && !empty($error)) {
            $this->errors[$tool] = $error;
        }
    }

    public function getErrors()
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
