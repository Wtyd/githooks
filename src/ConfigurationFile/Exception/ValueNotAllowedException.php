<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\ConfigurationFile\Exception;

class ValueNotAllowedException extends \UnexpectedValueException{
    /**
     * @var string The key of the configuration file.
     */
    protected $key;

    /**
     * @var mixed The value for the key.
     */
    protected $value;

    /**
     * @var array The possible values for the key.
     */
    protected $expectedValues;

    /**
     * Named constructor for the ValueNotAllowedException.
     *
     * @param string $key The key of the configuration file.
     * @param mixed $value The value for the key.
     * @param array $expectedValues The possible values for the key.
     *
     * @return ValueNotAllowedException
     */
    /**
     * @param mixed $value
     */
    public static function forKey(string $key, $value, array $expectedValues): ValueNotAllowedException
    {
        $valuesToString = implode(', ', $expectedValues);

        if (is_bool($value) === true) {
            $value = $value ? 'true' : 'false';
        }

        $exception = new self(
            "The value '$value' is not allowed for the tag '$key'. Accept: $valuesToString"
        );

        $exception->key = $key;
        $exception->value = $value;
        $exception->expectedValues = $expectedValues;

        return $exception;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * @return mixed
     */
    public function getValue()
    {
        return $this->value;
    }

    public function getExpectedValues(): array
    {
        return $this->expectedValues;
    }
}
