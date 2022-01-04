<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Tools\Tool;

use Wtyd\GitHooks\LoadTools\Exception\ToolDoesNotExistException;
use Wtyd\GitHooks\Tools\Tool\CodeSniffer\Phpcbf;
use Wtyd\GitHooks\Tools\Tool\CodeSniffer\Phpcs;

// TODO check for type and values for arguments.
// TODO arguments or options?
abstract class ToolAbstract
{
    /**
     * @const array Options for the tool. Must be override for each child.
     */
    public const OPTIONS = [];

    public const NAME = 'the name of the tool';

    public const TOOL_CONFIGURATION = 'toolConfiguration';

    public const PHPCS = 'phpcs';

    public const PHPCBF = 'phpcbf';

    public const SECURITY_CHECKER = 'security-checker';

    public const PARALLEL_LINT = 'parallel-lint';

    public const MESS_DETECTOR = 'phpmd';

    public const COPYPASTE_DETECTOR = 'phpcpd';

    public const PHPSTAN = 'phpstan';

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

    /**
     * @var string Name of tool printend when it is runned
     */
    protected $executable;

    /**
     * @var int
     */
    protected $exitCode = -1;

    /**
     * @var array
     */
    protected $exit = [];

    /**
     * @var string
     */
    protected $errors = '';

    /**
     * The arguments to run the tool.
     * Is an associative array where the keys are the tool OPTIONS
     *
     * @var array
     */
    protected $args = [];

    abstract protected function prepareCommand(): string;

    final protected function setArguments(array $configurationFile): void
    {
        foreach ($configurationFile as $key => $value) {
            if (empty($value)) {
                continue;
            }

            if (is_string($value)) {
                if (!$this->isWindows()) {
                    $this->args[$key] = $this->unixRouteCorrector($value);
                } else {
                    var_dump($value);
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
     * Executes the tool.
     * 1. WithLiveOutput is used when the tool is executed individually.
     * 2. Without live output means the tool is executed with the flag 'all'. Namely, when two or more tools are running together.
     *    Only one tool may be running. It depends on githooks.yml.
     *
     * @return void
     */
    public function execute(bool $withLiveOutput): void
    {
        if ($withLiveOutput) {
            $this->runWithLiveOutput($this->prepareCommand());
        } else {
            $this->run($this->prepareCommand());
        }
    }

    /**
     * This method is run by 'vendor/bin/githooks tool:...' commands. The output of the tool/s will be displayed in real time.
     *
     * @param string $command The command to be run
     * @return void
     */
    protected function runWithLiveOutput(string $command): void
    {
        echo  $command . "\n";
        passthru($command, $this->exitCode);
    }

    /**
     * Run the tool storing the output ($this->exit) and the exitCode
     *
     * @param string $command The command to be run
     * @return void
     */
    protected function run(string $command): void
    {
        exec($command, $this->exit, $this->exitCode);
    }

    public function getExitCode(): int
    {
        return $this->exitCode;
    }

    public function getExit(): array
    {
        return $this->exit;
    }

    public function getErrors(): string
    {
        return $this->errors;
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
