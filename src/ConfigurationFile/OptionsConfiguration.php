<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\ConfigurationFile;

use Illuminate\Support\Arr;
use Wtyd\GitHooks\ConfigurationFile\Exception\WrongExecutionValueException;
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

    /**
     * @var array
     */
    protected $errors = [];

    /**
     * @var array
     */
    protected $warnings = [];

    public function __construct(array $configurationFile)
    {
        if (!array_key_exists(self::OPTIONS_TAG, $configurationFile)) {
            return;
        }

        if (empty($configurationFile[self::OPTIONS_TAG])) {
            $this->warnings[] = 'The tag \'' . self::OPTIONS_TAG . '\' is empty';
            return;
        }

        // Assoc Array: Options => [execution => full]
        if (Arr::isAssoc($configurationFile[self::OPTIONS_TAG])) {
            $this->extractOptions($configurationFile[self::OPTIONS_TAG]);
        } else { // No Assoc Array: Options => [0 =>[execution => full]]
            foreach ($configurationFile[self::OPTIONS_TAG] as $option) {
                $this->extractOptions($option);
            }
        }
    }

    /**
     * Check for valid options and set them.
     *
     * @param array $options
     * @return void
     */
    protected function extractOptions(array $options): void
    {
        $this->warnings = $this->findWarnings($options);

        if (array_key_exists(self::EXECUTION_TAG, $options)) {
            $execution = $options[self::EXECUTION_TAG];
            try {
                $this->setExecution($execution);
            } catch (WrongExecutionValueException $ex) {
                $this->errors[] = $ex->getMessage();
            } catch (\TypeError $throwable) {
                $this->errors[] = WrongExecutionValueException::getExceptionMessage($execution);
            }
        }
    }

    /**
     * Verify unsupported keys.
     *
     * @param array $options Array of OPTIONS key. Now, only 'execution' is valid.
     *
     * @return array Found warnings.
     */
    protected function findWarnings(array $options): array
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

    /**
     * Undocumented function
     *
     * @param string $execution
     * @return void
     * @throws WrongExecutionValueException
     */
    public function setExecution(string $execution): void
    {
        if (is_string($execution) && in_array($execution, ExecutionMode::EXECUTION_KEY, true)) {
            $this->execution = $execution;
            return;
        }

        throw WrongExecutionValueException::forExecution($execution);
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
