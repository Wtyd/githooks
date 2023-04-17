<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\ConfigurationFile;

use Wtyd\GitHooks\ConfigurationFile\Exception\WrongOptionsFormatException;
use Wtyd\GitHooks\ConfigurationFile\Exception\WrongOptionsValueException;
use Wtyd\GitHooks\LoadTools\ExecutionMode;

class OptionsConfiguration
{
    public const OPTIONS_TAG = 'Options';

    public const EXECUTION_TAG = 'execution';

    public const PROCESSES_TAG = 'processes';

    public const TAGS_OPTIONS_TAG = [self::EXECUTION_TAG, self::PROCESSES_TAG];

    /** @var string full (default)/fast */
    protected $execution = 'full';

    /** @var bool False when execution is setted. Useful to know how to print the output of options (OptionsTable) */
    protected $defaultExecution = true;

    /** @var int */
    protected $processes = 1;

    /** @var bool False when processes is setted. Useful to know how to print the output of options (OptionsTable) */
    protected $defaultProcesses = true;

    /** @var array */
    protected $errors = [];

    /** @var array */
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

        if ($this->arrayIsAssociative($configurationFile[self::OPTIONS_TAG])) {
            $this->extractOptions($configurationFile[self::OPTIONS_TAG]);
        } else { // No Assoc Array: Options => [0 =>[execution => full]]
            throw WrongOptionsFormatException::forOptions($configurationFile[self::OPTIONS_TAG]);
        }
    }

    protected function arrayIsAssociative(array $array): bool
    {
        return !array_key_exists(0, $array);
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
                $this->defaultExecution = false;
            } catch (WrongOptionsValueException $ex) {
                $this->errors[] = $ex->getMessage();
            } catch (\TypeError $throwable) {
                $this->errors[] = WrongOptionsValueException::getExceptionMessage($execution);
            }
        }

        if (array_key_exists(self::PROCESSES_TAG, $options)) {
            $processes = $options[self::PROCESSES_TAG];
            try {
                $this->setProcesses($processes);
                $this->defaultProcesses = false;
            } catch (WrongOptionsValueException $ex) {
                $this->errors[] = $ex->getMessage();
            } catch (\TypeError $throwable) {
                $this->errors[] = WrongOptionsValueException::getExceptionMessageForProcesses($processes);
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

    protected function setWarningsForDefaultValues(): void
    {
        $this->defaultExecution = true;
        $this->defaultProcesses = true;
        $this->warnings[] = 'The default value for execution is full';
        $this->warnings[] = 'The default value for processes is 1';
    }

    /**
     * @param int $processes
     * @return void
     * @throws WrongOptionsValueException
     */
    public function setProcesses(int $processes): void
    {
        if ($processes < 1) {
            throw WrongOptionsValueException::forProcesses($processes);
        }
        $this->processes = $processes;
    }

    /**
     * @param string $execution
     * @return void
     * @throws WrongOptionsValueException
     */
    public function setExecution(string $execution): void
    {
        if (is_string($execution) && in_array($execution, ExecutionMode::EXECUTION_KEY, true)) {
            $this->execution = $execution;
            return;
        }

        throw WrongOptionsValueException::forExecution($execution);
    }

    public function getProcesses(): int
    {
        return $this->processes;
    }

    public function isDefaultProcesses(): bool
    {
        return $this->defaultProcesses;
    }

    public function getExecution(): string
    {
        return $this->execution;
    }

    public function isDefaultExecution(): bool
    {
        return $this->defaultExecution;
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
