<?php

namespace GitHooks;

/**
 * DTO para guardar los errores encontrados al validar el fichero de configuración
 */
class ConfigurationErrors
{
    protected $errors = [];
    protected $warnings = [];

    public function __construct(array $errors, array $warnings)
    {
        $this->errors = $errors;
        $this->warnings = $warnings;
    }
    /**
     * Get the value of errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Set the value of errors
     *
     * @return  self
     */
    public function setErrors(array $errors): ConfigurationErrors
    {
        $this->errors = $errors;

        return $this;
    }

    /**
     * Get the value of warnings
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Set the value of warnings
     *
     * @return  self
     */
    public function setWarnings(array $warnings): ConfigurationErrors
    {
        $this->warnings = $warnings;

        return $this;
    }
}
