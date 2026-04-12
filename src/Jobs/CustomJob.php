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
            if ($this->executable !== '') {
                $base = $this->getEffectiveExecutable();
            } else {
                $base = $this->executablePrefix !== ''
                    ? $this->executablePrefix . ' ' . $this->script
                    : $this->script;
            }
            $parts = [$base];
            $parts[] = is_array($paths) ? implode(' ', $paths) : $paths;

            if (!empty($this->args['otherArguments'])) {
                $parts[] = $this->args['otherArguments'];
            }

            if ($this->cliExtraArguments !== '') {
                $parts[] = $this->cliExtraArguments;
            }

            return implode(' ', $parts);
        }

        $command = $this->executablePrefix !== ''
            ? $this->executablePrefix . ' ' . $this->script
            : $this->script;

        if ($this->cliExtraArguments !== '') {
            $command .= ' ' . $this->cliExtraArguments;
        }

        return $command;
    }
}
