<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Jobs;

use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Execution\ExecutionContext;
use Wtyd\GitHooks\Execution\ThreadCapability;

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

    protected string $executablePrefix = '';

    protected string $cliExtraArguments = '';

    protected ?ExecutionContext $context = null;

    public function __construct(JobConfiguration $config)
    {
        $this->name = $config->getName();
        $this->type = $config->getType();
        $this->args = $config->getConfig();
        $this->executable = $this->args['executablePath'] ?? $this->resolveExecutable();
        unset($this->args['executablePath']);
        $this->ignoreErrorsOnExit = (bool) ($this->args['ignoreErrorsOnExit'] ?? false);
        unset($this->args['ignoreErrorsOnExit']);
        $this->failFast = (bool) ($this->args['failFast'] ?? false);
        unset($this->args['failFast']);
    }

    abstract public static function getDefaultExecutable(): string;

    public function getExecutable(): string
    {
        return $this->executable;
    }

    public function applyExecutablePrefix(string $prefix): void
    {
        $this->executablePrefix = $prefix;
    }

    public function applyCliExtraArguments(string $args): void
    {
        $this->cliExtraArguments = $args;
    }

    protected function getEffectiveExecutable(): string
    {
        if ($this->executablePrefix !== '') {
            return $this->executablePrefix . ' ' . $this->executable;
        }

        return $this->executable;
    }

    /**
     * Resolve executable path: try vendor/bin/{tool} first, fall back to tool name.
     */
    protected function resolveExecutable(): string
    {
        $default = static::getDefaultExecutable();
        if ($default === '') {
            return $default;
        }
        $vendorPath = 'vendor/bin/' . $default;
        if ($this->fileExistsCheck($vendorPath)) {
            return $vendorPath;
        }
        return $default;
    }

    protected function fileExistsCheck(string $path): bool
    {
        return file_exists($path);
    }

    /**
     * Subcommand inserted right after the executable (e.g. "analyse" for phpstan).
     */
    protected function getSubcommand(): string
    {
        return '';
    }

    /**
     * Build the full CLI command string from executable + ARGUMENT_MAP + args.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Iterates ARGUMENT_MAP types + appends optional parts
     */
    public function buildCommand(): string
    {
        $parts = [$this->getEffectiveExecutable()];

        $subcommand = $this->getSubcommand();
        if ($subcommand !== '') {
            $parts[] = $subcommand;
        }

        $pathsPart = '';

        foreach (static::ARGUMENT_MAP as $key => $spec) {
            if (!array_key_exists($key, $this->args) || $this->isEmpty($this->args[$key])) {
                continue;
            }
            $result = $this->buildArgumentPart($key, $this->args[$key], $spec);
            if ($result === null) {
                $pathsPart = is_array($this->args[$key]) ? implode(' ', $this->args[$key]) : $this->args[$key];
            } else {
                array_push($parts, ...$result);
            }
        }

        if (!empty($this->args['otherArguments'])) {
            $parts[] = $this->args['otherArguments'];
        }

        if ($this->cliExtraArguments !== '') {
            $parts[] = $this->cliExtraArguments;
        }

        if ($pathsPart !== '') {
            $parts[] = $pathsPart;
        }

        return implode(' ', $parts);
    }

    /**
     * Build the CLI fragment(s) for a single argument.
     *
     * @param string $key  Config key name
     * @param mixed  $value Argument value (already validated as non-empty)
     * @param array<string, string> $spec  ARGUMENT_MAP entry
     * @return string[]|null Parts to append, or null for 'paths' (deferred)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Switch covers all ARGUMENT_MAP types
     */
    protected function buildArgumentPart(string $key, $value, array $spec): ?array
    {
        $flag = $spec['flag'] ?? '';
        $separator = $spec['separator'] ?? '=';

        switch ($spec['type'] ?? 'value') {
            case 'value':
                return [$flag . $separator . $value];
            case 'boolean':
                return $value ? [$flag] : [];
            case 'paths':
                return null;
            case 'csv':
                $list = is_array($value) ? implode(',', $value) : $value;
                return [$flag . $separator . $list];
            case 'repeat':
                $parts = [];
                foreach ((array) $value as $item) {
                    $parts[] = $flag . ' ' . $item;
                }
                return $parts;
            case 'key_value':
                return ["--$key=$value"];
            default:
                return [$flag . $separator . $value];
        }
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

    public function setExecutionContext(ExecutionContext $context): void
    {
        $this->context = $context;
    }

    /**
     * Declare threading capability for budget allocation.
     * Override in subclasses that support internal parallelism.
     */
    public function getThreadCapability(): ?ThreadCapability
    {
        return null;
    }

    /**
     * Apply thread limit from budget allocator.
     * Override in subclasses that support internal parallelism.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function applyThreadLimit(int $threads): void
    {
        // no-op by default
    }

    /** @SuppressWarnings(PHPMD.UnusedFormalParameter) */
    public function isFixApplied(int $exitCode): bool
    {
        return false;
    }

    /**
     * Return paths to cache files/directories used by this tool.
     * Override in subclasses that produce caches.
     *
     * @return string[]
     */
    public function getCachePaths(): array
    {
        return [];
    }

    /** @param mixed $value */
    private function isEmpty($value): bool
    {
        if (is_bool($value)) {
            return false; // booleans are never "empty" for our purposes
        }
        return empty($value);
    }
}
