<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\ConfigurationFile;

use Wtyd\GitHooks\LoadTools\ExecutionMode;

class OptionsConfiguration
{
    public const OPTIONS_TAG = 'Options';

    public const EXECUTION_TAG = 'execution';

    public const TAGS_OPTIONS_TAG = [self::EXECUTION_TAG];

    /**
     * @var string
     */
    protected $execution = '';

    protected $errors = [];

    protected $warnings = [];

    public function __construct(array $configurationFile)
    {
        if (!array_key_exists(self::OPTIONS_TAG, $configurationFile)) {
            return;
        }

        if (empty($configurationFile[self::OPTIONS_TAG])) {
            $this->warnings[] = 'The tag \'' . self::OPTIONS_TAG . '\' is empty';
        } else {
            $this->warnings = $this->checkValidKeys($configurationFile[self::OPTIONS_TAG]);

            if (array_key_exists(self::EXECUTION_TAG, $configurationFile[self::OPTIONS_TAG])) {
                $this->setExecution($configurationFile[self::OPTIONS_TAG][self::EXECUTION_TAG]);
            }
        }
    }

    /**
     * Verify unsupported keys.
     *
     * @param array $options Array of OPTIONS key.
     *
     * @return array This type of errors are warnings.
     */
    protected function checkValidKeys(array $options): array
    {
        $warnings = [];
        $keys = array_keys($options);
        $invalidKeys = array_diff($keys, self::TAGS_OPTIONS_TAG);

        if (!empty($invalidKeys)) {
            foreach ($invalidKeys as $key) {
                $warnings[] = 'The key \'' . $key . '\' is not a valid option';
            }
        }

        return $warnings;
    }


    public function setExecution(string $execution): void
    {
        if (in_array($execution, ExecutionMode::EXECUTION_KEY, true)) {
            $this->execution = $execution;
        } else {
            $valuesToString = implode(', ', ExecutionMode::EXECUTION_KEY);
            $this->errors[] = "The value '$execution' is not allowed for the tag '" . self::EXECUTION_TAG . "'. Accept: $valuesToString";
        }
    }

    public function getExecution(): string
    {
        return $this->execution;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }
}
