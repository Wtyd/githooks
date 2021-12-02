<?php

namespace Wtyd\GitHooks\Tools\Tool;

use Wtyd\GitHooks\LoadTools\Exception\ToolDoesNotExistException;
use Wtyd\GitHooks\Tools\Tool\CodeSniffer\Phpcbf;
use Wtyd\GitHooks\Tools\Tool\CodeSniffer\Phpcs;

abstract class ToolAbstract
{
    public const TOOL_CONFIGURATION = 'toolConfiguration';

    //TODO renombrar a phpcs
    // TODO check for mandatory arguments
    // TODO check for type and values for arguments.
    // TODO arguments or options?
    public const CODE_SNIFFER = 'phpcs';

    public const PHPCBF = 'phpcbf';

    public const SECURITY_CHECKER = 'security-checker';

    public const PARALLEL_LINT = 'parallel-lint';

    public const MESS_DETECTOR = 'phpmd';

    public const COPYPASTE_DETECTOR = 'phpcpd';

    public const PHPSTAN = 'phpstan';

    public const SUPPORTED_TOOLS = [
        self::CODE_SNIFFER => Phpcs::class,
        self::PHPCBF => Phpcbf::class,
        self::SECURITY_CHECKER => SecurityChecker::class,
        self::PARALLEL_LINT => ParallelLint::class,
        self::MESS_DETECTOR => Phpmd::class,
        self::COPYPASTE_DETECTOR => Phpcpd::class,
        self::PHPSTAN => Phpstan::class,
    ];

    public const EXCLUDE_ARGUMENT = [
        self::CODE_SNIFFER => Phpcs::IGNORE,
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
     * @var string
     */
    protected $executablePath;

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

    abstract public function setArguments(array $configurationFile): void;

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
     * Replaces / by \ when the app run in Windows
     *
     * @param string $path
     * @return string path
     */
    protected function routeCorrector(string $path): string
    {
        if (!$this->isWindows()) {
            return $path;
        }

        return str_replace('/', '\\', $path);
    }

    /**
     * Replaces / by \ when the app run in Windows
     *
     * @param array $paths
     * @return array paths
     */
    protected function multipleRoutesCorrector(array $paths): array
    {
        if (!$this->isWindows()) {
            return $paths;
        }
        $rightPaths = [];
        foreach ($paths as $path) {
            $rightPaths[] = str_replace('/', '\\', $path);
        }

        return $rightPaths;
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

    public function getExecutable(): string
    {
        return $this->executable;
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
