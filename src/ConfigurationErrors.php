<?php

namespace GitHooks;

/**
 * DTO for storage errors and warnings founds with to the configuration file validation.
 */
class ConfigurationErrors
{
    /**
     * @var array
     */
    protected $optionsErrors = [];

    /**
     * @var array
     */
    protected $optionsWarnings = [];

    /**
     * @var array
     */
    protected $toolsErrors = [];

    /**
     * @var array
     */
    protected $toolsWarnings = [];

    public function __construct()
    {
        $this->optionsErrors = [];
        $this->optionsWarnings = [];
        $this->toolsErrors = [];
        $this->toolsWarnings = [];
    }

    public function getOptionsErrors(): array
    {
        return $this->optionsErrors;
    }

    public function getOptionsWarnings(): array
    {
        return $this->optionsWarnings;
    }

    public function getToolsErrors(): array
    {
        return $this->toolsErrors;
    }

    public function getToolsWarnings(): array
    {
        return $this->toolsWarnings;
    }

    public function getAllErrors(): array
    {
        return array_merge($this->optionsErrors, $this->toolsErrors);
    }

    public function getAllWarnings(): array
    {
        return array_merge($this->optionsWarnings, $this->toolsWarnings);
    }

    public function setOptionsErrors(array $optionsErrors): ConfigurationErrors
    {
        $this->optionsErrors = $optionsErrors;

        return $this;
    }

    public function setOptionsWarnings(array $optionsWarnings): ConfigurationErrors
    {
        $this->optionsWarnings = $optionsWarnings;

        return $this;
    }

    public function setToolsErrors(array $toolsErrors): ConfigurationErrors
    {
        $this->toolsErrors = $toolsErrors;

        return $this;
    }

    public function setToolsWarnings(array $toolsWarnings): ConfigurationErrors
    {
        $this->toolsWarnings = $toolsWarnings;

        return $this;
    }

    public function setErrors(array $optionsErrors, array $toolsErrors): ConfigurationErrors
    {
        $this->optionsErrors = $optionsErrors;
        $this->toolsErrors = $toolsErrors;

        return $this;
    }

    public function setWarnings(array $optionsWarnings, array $toolsWarnings): ConfigurationErrors
    {
        $this->optionsWarnings = $optionsWarnings;
        $this->toolsWarnings = $toolsWarnings;

        return $this;
    }

    public function addOptionsErrors(array $optionsErrors): ConfigurationErrors
    {
        $this->optionsErrors = array_merge($this->optionsErrors, $optionsErrors);

        return $this;
    }

    public function addToolsErrors(array $toolsErrors): ConfigurationErrors
    {
        $this->toolsErrors = array_merge($this->toolsErrors, $toolsErrors);

        return $this;
    }

    public function addErrors(array $optionsErrors, array $toolsErrors): ConfigurationErrors
    {
        $this->optionsErrors = array_merge($this->optionsErrors, $optionsErrors);
        $this->toolsErrors = array_merge($this->toolsErrors, $toolsErrors);

        return $this;
    }

    public function addOptionsWarnings(array $optionsWarnings): ConfigurationErrors
    {
        $this->optionsWarnings = array_merge($this->optionsWarnings, $optionsWarnings);

        return $this;
    }

    public function addToolsWarnings(array $toolsWarnings): ConfigurationErrors
    {
        $this->toolsWarnings = array_merge($this->toolsWarnings, $toolsWarnings);

        return $this;
    }

    public function addWarnings(array $optionsWarnings, array $toolsWarnings): ConfigurationErrors
    {
        $this->optionsWarnings = array_merge($this->optionsWarnings, $optionsWarnings);
        $this->toolsWarnings = array_merge($this->toolsWarnings, $toolsWarnings);

        return $this;
    }

    public function hasErrors(): bool
    {
        if (empty($this->optionsErrors) && empty($this->toolsErrors)) {
            return false;
        }

        return true;
    }

    public function hasWarnings(): bool
    {
        if (empty($this->optionsWarnings) && empty($this->toolsWarnings)) {
            return false;
        }

        return true;
    }
}
