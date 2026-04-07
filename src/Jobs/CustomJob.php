<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Jobs;

use Wtyd\GitHooks\Configuration\JobConfiguration;

/**
 * Executes an arbitrary command. Two modes:
 *
 * 1. Structured (with paths): executablePath + paths + otherArguments
 *    Supports fast mode via path filtering, same as standard tools.
 *
 * 2. Legacy (without paths): script is the full command verbatim.
 */
class CustomJob extends JobAbstract
{
    public const SUPPORTS_FAST = false;

    protected const ARGUMENT_MAP = [];

    private string $script;

    public function __construct(JobConfiguration $config)
    {
        parent::__construct($config);
        $this->script = $config->getConfig()['script'] ?? '';
    }

    public static function getDefaultExecutable(): string
    {
        return '';
    }

    public function buildCommand(): string
    {
        $paths = $this->args['paths'] ?? [];

        if (!empty($paths)) {
            $parts = [$this->executable !== '' ? $this->executable : $this->script];
            $parts[] = is_array($paths) ? implode(' ', $paths) : $paths;

            if (!empty($this->args['otherArguments'])) {
                $parts[] = $this->args['otherArguments'];
            }

            return implode(' ', $parts);
        }

        return $this->script;
    }
}
