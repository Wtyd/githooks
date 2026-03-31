<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Jobs;

use Wtyd\GitHooks\Configuration\JobConfiguration;

/**
 * Base class for all job types. Subclasses declare ARGUMENT_MAP and the base
 * buildCommand() produces the CLI string. Only truly exceptional tools need
 * to override buildCommand().
 *
 * ARGUMENT_MAP entry format:
 *   'configKey' => ['flag' => '--flag', 'type' => 'value|boolean|paths|csv|repeat|key_value']
 *
 * Types:
 *   value     — --flag=value or -f value  (uses 'separator' => '=' or ' ')
 *   boolean   — --flag (present when truthy, omitted when falsy)
 *   paths     — space-separated list appended at the end
 *   csv       — --flag=a,b,c
 *   repeat    — --flag a --flag b (flag repeated per value)
 *   key_value — --key=value (flag equals the config key name)
 */
abstract class JobAbstract
{
    protected const ARGUMENT_MAP = [];

    protected string $name;

    protected string $type;

    protected string $executable;

    /** @var array<string, mixed> */
    protected array $args;

    protected bool $ignoreErrorsOnExit;

    protected bool $failFast;

    public function __construct(JobConfiguration $config)
    {
        $this->name = $config->getName();
        $this->type = $config->getType();
        $this->args = $config->getConfig();
        $this->executable = $this->args['executablePath'] ?? static::getDefaultExecutable();
        unset($this->args['executablePath']);
        $this->ignoreErrorsOnExit = (bool) ($this->args['ignoreErrorsOnExit'] ?? false);
        unset($this->args['ignoreErrorsOnExit']);
        $this->failFast = (bool) ($this->args['failFast'] ?? false);
        unset($this->args['failFast']);
    }

    abstract public static function getDefaultExecutable(): string;

    /**
     * Subcommand inserted right after the executable (e.g. "analyse" for phpstan).
     */
    protected function getSubcommand(): string
    {
        return '';
    }

    /**
     * Build the full CLI command string from executable + ARGUMENT_MAP + args.
     */
    public function buildCommand(): string
    {
        $parts = [$this->executable];

        $subcommand = $this->getSubcommand();
        if ($subcommand !== '') {
            $parts[] = $subcommand;
        }

        $pathsPart = '';

        foreach (static::ARGUMENT_MAP as $key => $spec) {
            if (!array_key_exists($key, $this->args)) {
                continue;
            }

            $value = $this->args[$key];

            if ($this->isEmpty($value)) {
                continue;
            }

            $flag = $spec['flag'] ?? '';
            $type = $spec['type'] ?? 'value';
            $separator = $spec['separator'] ?? '=';

            switch ($type) {
                case 'value':
                    $parts[] = $flag . $separator . $value;
                    break;
                case 'boolean':
                    if ($value) {
                        $parts[] = $flag;
                    }
                    break;
                case 'paths':
                    // Deferred — always appended at end
                    $pathsPart = is_array($value) ? implode(' ', $value) : $value;
                    break;
                case 'csv':
                    $list = is_array($value) ? implode(',', $value) : $value;
                    $parts[] = $flag . $separator . $list;
                    break;
                case 'repeat':
                    foreach ((array) $value as $item) {
                        $parts[] = $flag . ' ' . $item;
                    }
                    break;
                case 'key_value':
                    $parts[] = "--$key=$value";
                    break;
            }
        }

        // otherArguments — raw string appended before paths
        if (!empty($this->args['otherArguments'])) {
            $parts[] = $this->args['otherArguments'];
        }

        if ($pathsPart !== '') {
            $parts[] = $pathsPart;
        }

        return implode(' ', $parts);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getDisplayName(): string
    {
        return $this->name;
    }

    public function isIgnoreErrorsOnExit(): bool
    {
        return $this->ignoreErrorsOnExit;
    }

    public function isFailFast(): bool
    {
        return $this->failFast;
    }

    public function isFixApplied(int $exitCode): bool
    {
        return false;
    }

    private function isEmpty($value): bool
    {
        if (is_bool($value)) {
            return false; // booleans are never "empty" for our purposes
        }
        return empty($value);
    }
}
