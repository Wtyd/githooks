<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\ConfigurationFile;

use Illuminate\Support\Arr;
use Wtyd\GitHooks\ConfigurationFile\Exception\WrongOptionsFormatException;
use Wtyd\GitHooks\ConfigurationFile\Exception\WrongOptionsValueException;
use Wtyd\GitHooks\LoadTools\ExecutionMode;

class OptionsConfiguration
{
    public const OPTIONS_TAG = 'Options';

    public const EXECUTION_TAG = 'execution';

    public const PROCESSES_TAG = 'processes';

    public const TAGS_OPTIONS_TAG = [self::EXECUTION_TAG, self::PROCESSES_TAG];

    /** @var string */
    protected $execution = '';

    /** @var int */
    protected $processes = 1;

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
