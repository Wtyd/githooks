<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Tools\Tool;

abstract class ToolAbstract
{
    /**
     * @var array Arguments for the tool. Must be override for each child.
     */
    public const ARGUMENTS = [];

    /**
     * @var string Name of the tool. Must be override for each child.
     */
    public const NAME = 'the name of the tool';

    public const TOOL_CONFIGURATION = 'toolConfiguration';

    public const EXECUTABLE_PATH_OPTION = 'executablePath';

    public const OTHER_ARGS_OPTION = 'otherArguments';

    public const IGNORE_ERRORS_ON_EXIT = 'ignoreErrorsOnExit';

    public const FAIL_FAST = 'failFast';

    protected string $executable;

    protected array $args = [];

    /** @return string The tool command line based on the tool configuration */
    abstract public function prepareCommand(): string;

    final protected function setArguments(array $configurationFile): void
    {
        foreach ($configurationFile as $key => $value) {
            if (empty($value) && !is_bool($value)) {
                continue;
            }

            if (is_string($value)) {
                if (!$this->isWindows()) {
                    $this->args[$key] = $this->unixRouteCorrector($value);
                } else {
                    $this->args[$key] = $this->windowsRouteCorrector($value);
                }
                continue;
            } elseif (is_array($value)) {
                $this->args[$key] = $this->routesCorrector($value);
            } else {
                // other type
                $this->args[$key] = $value;
            }
        }
    }

    /**
     * Devuelve un array añadiendo el prefijo a cada uno de los elementos
     *
     * @param array $array
     * @param string $prefix
     * @return array
     */
    protected function addPrefixToArray(array $array, string $prefix)
    {
        return array_map(function ($arrayValues) use ($prefix) {
            return $prefix . $arrayValues;
        }, $array);
    }

    /**
     * Replaces the directory separator if necessary.
     *
     * @param array $paths
     * @return array $rightPaths $paths fixed with de correct directory separator.
     */
    protected function routesCorrector(array $paths): array
    {
        $rightPaths = [];
        if (!$this->isWindows()) {
            foreach ($paths as $path) {
                $rightPaths[] = is_string($path) ? $this->unixRouteCorrector($path) : $path;
            }
        } else {
            foreach ($paths as $path) {
                $rightPaths[] = is_string($path) ? $this->windowsRouteCorrector($path) : $path;
            }
        }

        return $rightPaths;
    }

    protected function windowsRouteCorrector(string $path): string
    {
        return str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    protected function unixRouteCorrector(string $path): string
    {
        return str_replace('\\', DIRECTORY_SEPARATOR, $path);
    }

    /**
     * Checks if it is running in Windows
     *
     * @return boolean
     */
    protected function isWindows()
    {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    /**
     * Returns the display name used in the output (e.g. "toolName - OK. Time: X.XX").
     * By default returns the executable property. Script overrides this to show the executablePath value.
     *
     * @return string
     */
    public function getDisplayName(): string
    {
        return $this->executable;
    }

    public function isIgnoreErrorsOnExit(): bool
    {
        return $this->args[self::IGNORE_ERRORS_ON_EXIT] ?? false;
    }

    public function isFailFast(): bool
    {
        return $this->args[self::FAIL_FAST] ?? false;
    }

    /**
     * Indicates whether the given exit code means the tool applied fixes to files.
     * Auto-fixing tools (like phpcbf) should override this method.
     *
     * @param int $exitCode
     * @return bool
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function isFixApplied(int $exitCode): bool
    {
        return false;
    }

}
