<?php

namespace Wtyd\GitHooks;

class ConfigurationFileValidator
{
    /**
     * Validate the $configurationFile format
     *
     * @param array $configurationFile. Configuration File.
     *
     * @return ConfigurationErrors POPO that contains an array of errors and one of warnings.
     */
    public function __invoke(array $configurationFile): ConfigurationErrors
    {
        $configurationErrors = new ConfigurationErrors();

        $optionsErrorsAndWarnings = $this->checkOptionsKey($configurationFile);

        $toolsErrorsAndWarnings = $this->checkToolsKey($configurationFile);

        return $configurationErrors->setOptionsErrors($optionsErrorsAndWarnings[0])
            ->setOptionsWarnings($optionsErrorsAndWarnings[1])
            ->setToolsErrors($toolsErrorsAndWarnings[0])
            ->setToolsWarnings($toolsErrorsAndWarnings[1]);
    }

    /**
     * Verify unsupported keys.
     *
     * @param array $array Can be $configurationFile or a subarray.
     * @param array $validKeys Supported keys.
     * @return array This type of errors are warnings.
     */
    protected function checkValidKeys(array $array, array $validKeys): array
    {
        $warnings = [];
        $keys = array_keys($array);
        $invalidKeys = array_diff($keys, $validKeys);

        if (!empty($invalidKeys)) {
            foreach ($invalidKeys as $key) {
                $warnings[] = 'The key \'' . $key . '\' is not a valid option';
            }
        }

        return $warnings;
    }

    /**
     * If Options tag exist, the method proves that both the keys and the values are valid.
     *
     * @param array $configurationFile
     * @return array
     */
    protected function checkOptionsKey(array $configurationFile): array
    {

        $errors = [];
        $warnings = [];
        if (!array_key_exists(Constants::OPTIONS, $configurationFile)) {
            return [$errors, $warnings];
        }

        if (empty($configurationFile[Constants::OPTIONS])) {
            $warnings[] = 'The tag \'' . Constants::OPTIONS . '\' is empty';
        } else {
            $warnings = $this->checkValidKeys($configurationFile[Constants::OPTIONS], Constants::OPTIONS_KEY);

            $validItems = $this->extractValidItems($configurationFile[Constants::OPTIONS]);

            $errors = $this->checkValues($validItems, Constants::EXECUTION_KEY);
        }

        return [$errors, $warnings];
    }

    protected function extractValidItems(array $options): array
    {
        $validItems = [];
        foreach ($options as $key => $value) {
            if (in_array($key, Constants::OPTIONS_KEY)) {
                $validItems[$key] = $value;
            }
        }

        return $validItems;
    }

    /**
     * Check what each value from $values exists on $validValues. If a value not exists set an error.
     *
     * @param array $values Values to check.
     * @param array $validValues Possible values.
     * @return array
     */
    protected function checkValues(array $values, array $validValues): array
    {
        $valuesToString = implode(', ', $validValues);
        $errors = [];
        foreach ($values as $key => $value) {
            if (in_array($value, $validValues, true)) {
                continue;
            }
            if (is_bool($value) === true) { //Si el valor incorrecto es un booleano lo transformamos a string
                $value = $value ? 'true' : 'false';
            }
            $errors[] = "The value '$value' is not allowed for the tag '$key'. Accept: $valuesToString";
        }

        return $errors;
    }

    /**
     * Validate the 'Tools' key:
     * 1. The 'Tools' key must exist and not be null.
     * 2. The 'Tools' key must have at least one valid tool. The valid tools are the keys of Constants::TOOL_LIST array.
     * 3. All tools except 'check-security' must have a key at the root of $configurationFile with their configuration.
     * 4. The parameters extracted from the $configurationFile of each tool are compared with their possible configuration parameters.
     *
     * @param array $configurationFile
     * @return array
     */
    protected function checkToolsKey(array $configurationFile): array
    {
        $errors = [];
        $warnings = [];
        $atLeastOneValidTool = false;


        if (!isset($configurationFile[Constants::TOOLS]) || empty($configurationFile[Constants::TOOLS])) {
            $errors[] = "The key 'Tools' must exists.";
            return [$errors, $warnings];
        }

        foreach ($configurationFile[Constants::TOOLS] as $tool) {
            if (Constants::CHECK_SECURITY === $tool) {
                $atLeastOneValidTool = true;
                continue;
            }

            if (!array_key_exists($tool, Constants::TOOL_LIST)) {
                $warnings[] = "The tool $tool is not supported by GitHooks.";
            } elseif (!array_key_exists($tool, $configurationFile)) {
                $errors[] = "The tool $tool is not setting.";
            } else {
                $toolErrors = $this->checkConfiguration($configurationFile[$tool], Constants::TOOL_LIST[$tool]::OPTIONS, $tool);

                $atLeastOneValidTool = true;

                $errors = array_merge($errors, $toolErrors[Constants::ERRORS]);
                $warnings = array_merge($warnings, $toolErrors[Constants::WARNINGS]);
            }
        }

        if (!$atLeastOneValidTool) {
            $errors[] = 'There must be at least one tool configured.';
        }


        return [$errors, $warnings];
    }

    /**
     * Verifica que los $configurationArguments se corresponda con alguno de los $expectedValues.
     *
     * @param array $configurationArguments Argumentos para la herramienta leídos del fichero de configuración.
     * @param array $expectedValues Argumentos válidos de la herramienta. Es la constante OPTIONS de cada herramienta.
     * @param string $tool El nombre de la herramienta.
     * @return array Array bidimensional donde la key ERRORS es una array de errores y la key WARNINGS es un array de warnings.
     */
    protected function checkConfiguration(array $configurationArguments, array $expectedValues, string $tool): array
    {
        $errors = [];
        $warnings = [];

        foreach (array_keys($configurationArguments) as $key) {
            if (!in_array($key, $expectedValues)) {
                $warnings[] = "$key argument is invalid for tool $tool";
            }
        }

        return [Constants::ERRORS => $errors, Constants::WARNINGS => $warnings];
    }
}
