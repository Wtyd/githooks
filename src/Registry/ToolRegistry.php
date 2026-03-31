<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Registry;

use InvalidArgumentException;
use Wtyd\GitHooks\LoadTools\Exception\ToolDoesNotExistException;
use Wtyd\GitHooks\Tools\Tool\CodeSniffer\Phpcbf;
use Wtyd\GitHooks\Tools\Tool\CodeSniffer\Phpcs;
use Wtyd\GitHooks\Tools\Tool\ParallelLint;
use Wtyd\GitHooks\Tools\Tool\Phpcpd;
use Wtyd\GitHooks\Tools\Tool\Phpmd;
use Wtyd\GitHooks\Tools\Tool\Phpstan;
use Wtyd\GitHooks\Tools\Tool\Phpunit;
use Wtyd\GitHooks\Tools\Tool\Psalm;
use Wtyd\GitHooks\Tools\Tool\Script;
use Wtyd\GitHooks\Tools\Tool\SecurityChecker;

class ToolRegistry
{
    public const PHPCS = 'phpcs';
    public const PHPCBF = 'phpcbf';
    public const SECURITY_CHECKER = 'security-checker';
    public const PARALLEL_LINT = 'parallel-lint';
    public const MESS_DETECTOR = 'phpmd';
    public const COPYPASTE_DETECTOR = 'phpcpd';
    public const PHPSTAN = 'phpstan';
    public const PHPUNIT = 'phpunit';
    public const PSALM = 'psalm';
    public const SCRIPT = 'script';
    public const ALL_TOOLS = 'all';

    private const SUPPORTED_TOOLS = [
        self::PHPCS => Phpcs::class,
        self::PHPCBF => Phpcbf::class,
        self::SECURITY_CHECKER => SecurityChecker::class,
        self::PARALLEL_LINT => ParallelLint::class,
        self::MESS_DETECTOR => Phpmd::class,
        self::COPYPASTE_DETECTOR => Phpcpd::class,
        self::PHPSTAN => Phpstan::class,
        self::PHPUNIT => Phpunit::class,
        self::PSALM => Psalm::class,
        self::SCRIPT => Script::class,
    ];

    private const EXCLUDE_ARGUMENT = [
        self::PHPCS => Phpcs::IGNORE,
        self::PHPCBF => Phpcbf::IGNORE,
        self::SECURITY_CHECKER => '',
        self::PARALLEL_LINT => ParallelLint::EXCLUDE,
        self::MESS_DETECTOR => Phpmd::EXCLUDE,
        self::COPYPASTE_DETECTOR => Phpcpd::EXCLUDE,
        self::PHPSTAN => '',
        self::PHPUNIT => '',
        self::PSALM => '',
        self::SCRIPT => '',
    ];

    private ?string $scriptAlias = null;

    public function isSupported(string $tool): bool
    {
        return array_key_exists($tool, self::SUPPORTED_TOOLS)
            || ($this->scriptAlias !== null && $tool === $this->scriptAlias);
    }

    public function resolve(string $tool): string
    {
        if ($this->scriptAlias !== null && $tool === $this->scriptAlias) {
            return self::SCRIPT;
        }

        return $tool;
    }

    public function getClass(string $tool): string
    {
        $resolved = $this->resolve($tool);
        if (!array_key_exists($resolved, self::SUPPORTED_TOOLS)) {
            throw ToolDoesNotExistException::forTool($tool);
        }
        return self::SUPPORTED_TOOLS[$resolved];
    }

    public function isAccelerable(string $tool): bool
    {
        $resolved = $this->resolve($tool);
        if (!array_key_exists($resolved, self::SUPPORTED_TOOLS)) {
            return false;
        }
        $class = self::SUPPORTED_TOOLS[$resolved];
        return defined("$class::SUPPORTS_FAST") && $class::SUPPORTS_FAST;
    }

    public function getExcludeArgument(string $tool): string
    {
        $resolved = $this->resolve($tool);
        if (!$this->isSupported($resolved)) {
            throw ToolDoesNotExistException::forTool($tool);
        }
        return self::EXCLUDE_ARGUMENT[$resolved];
    }

    public function getSupportedToolNames(): array
    {
        $names = array_keys(self::SUPPORTED_TOOLS);
        if ($this->scriptAlias !== null) {
            $names[] = $this->scriptAlias;
        }
        return $names;
    }

    public function registerScriptAlias(string $alias): void
    {
        if (array_key_exists($alias, self::SUPPORTED_TOOLS)) {
            throw new InvalidArgumentException(
                "The script name '$alias' conflicts with an existing supported tool."
            );
        }

        $this->scriptAlias = $alias;
    }

    public function getScriptAlias(): ?string
    {
        return $this->scriptAlias;
    }

    public function resetScriptAlias(): void
    {
        $this->scriptAlias = null;
    }

    /**
     * If the 'script' section has a 'name' attribute, renames the config key
     * from 'script' to the custom name and registers the alias.
     */
    public function resolveScriptName(array $configurationFile): array
    {
        if (!isset($configurationFile[self::SCRIPT]['name'])) {
            return $configurationFile;
        }

        $name = $configurationFile[self::SCRIPT]['name'];

        if (empty($name) || !is_string($name)) {
            return $configurationFile;
        }

        if ($this->scriptAlias === null) {
            $this->registerScriptAlias($name);
        }

        $configurationFile[$name] = $configurationFile[self::SCRIPT];
        unset($configurationFile[$name]['name']);
        unset($configurationFile[self::SCRIPT]);

        return $configurationFile;
    }
}
