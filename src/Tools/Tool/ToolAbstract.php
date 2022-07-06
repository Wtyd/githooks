<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Tools\Tool;

use Wtyd\GitHooks\LoadTools\Exception\ToolDoesNotExistException;
use Wtyd\GitHooks\Tools\Tool\CodeSniffer\Phpcbf;
use Wtyd\GitHooks\Tools\Tool\CodeSniffer\Phpcs;

// TODO check for type and values for arguments.
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

    public const PHPCS = 'phpcs';

    public const PHPCBF = 'phpcbf';

    public const SECURITY_CHECKER = 'security-checker';

    public const PARALLEL_LINT = 'parallel-lint';

    public const MESS_DETECTOR = 'phpmd';

    public const COPYPASTE_DETECTOR = 'phpcpd';

    public const PHPSTAN = 'phpstan';

    public const ALL_TOOLS = 'all';

    public const SUPPORTED_TOOLS = [
        self::PHPCS => Phpcs::class,
        self::PHPCBF => Phpcbf::class,
        self::SECURITY_CHECKER => SecurityChecker::class,
        self::PARALLEL_LINT => ParallelLint::class,
        self::MESS_DETECTOR => Phpmd::class,
        self::COPYPASTE_DETECTOR => Phpcpd::class,
        self::PHPSTAN => Phpstan::class,
    ];

    public const EXCLUDE_ARGUMENT = [
        self::PHPCS => Phpcs::IGNORE,
        self::PHPCBF => Phpcbf::IGNORE,
        self::SECURITY_CHECKER => '',
        self::PARALLEL_LINT => ParallelLint::EXCLUDE,
        self::MESS_DETECTOR => Phpmd::EXCLUDE,
        self::COPYPASTE_DETECTOR => Phpcpd::EXCLUDE,
        self::PHPSTAN => '',
    ];

    public const EXECUTABLE_PATH_OPTION = 'executablePath';

    public const OTHER_ARGS_OPTION = 'otherArguments';

    public const IGNORE_ERRORS_ON_EXIT = 'ignoreErrorsOnExit';

    /** @var string Name of tool printend when it is runned */
    protected $executable;


    /** @var string */
    protected $errors = '';

    /** @var array Is an associative array where the keys are the tool ARGUMENTS */
    protected $args = [];

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

    public function getErrors(): string
    {
        return $this->errors;
    }

    public function isIgnoreErrorsOnExit(): bool
    {
        return $this->args[self::IGNORE_ERRORS_ON_EXIT] ?? false;
    }

    public static function checkTool(string $tool): bool
    {
        return array_key_exists($tool, self::SUPPORTED_TOOLS);
    }

    public static function excludeArgumentForTool(string $tool): string
    {
        if (!self::checkTool($tool)) {
            throw ToolDoesNotExistException::forTool($tool);
        }
        return self::EXCLUDE_ARGUMENT[$tool];
    }
}
