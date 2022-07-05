<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\ConfigurationFile\Exception;

class WrongOptionsFormatException extends \DomainException implements ConfigurationFileInterface
{
    /** @var array */
    private $options;

    /**
     * @param array $options
     * @return WrongOptionsFormatException
     */
    public static function forOptions(array $options): self
    {

        $exception = new self('The Options label has an invalid format. It must be an associative array with pair of key: value.');

        $exception->options = $options;

        return $exception;
    }

    public function getOptions(): array
    {
        return $this->options;
    }
}
