<?php

namespace Tests\Utils;

use Symfony\Component\Yaml\Yaml;
use Wtyd\GitHooks\ConfigurationFile\ConfigurationFile;
use Wtyd\GitHooks\ConfigurationFile\OptionsConfiguration;
use Wtyd\GitHooks\LoadTools\ExecutionMode;
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
use Wtyd\GitHooks\Registry\ToolRegistry;

/**
 * Facilitates the creation of custom 'githooks.yml' configuration files for testing.
 * By default prepares a full configuration file. After, you can change each part of the file with the 'set' methods.
 * Finally you must 'build' the file:
 *      1. build method: build the file on array associative format.
 *      2. buildYaml: build the file on yalm format.
 */
class ConfigurationFileBuilder
{
    public const FILE_NAME = 'githooks.php';
    public const PHAR_TOOLS_PATH = 'phar';
    public const GLOBAL_TOOLS_PATH = 'global';
    public const LOCAL_TOOLS_PATH = 'local';

    protected $name;

    protected $rootPath;

    protected $options;

    protected $tools;

    protected $configurationTools;

    protected $executablesPath;

    protected $mainToolExecutablePaths;

    /**
     * Set the attributes with default values.
     *
     * @param string $rootPath Customize what path you would as project root
     * @param string $toolsPath The way to find the executables of the tools
     *                      phar: the full path to the executables (example: tools/php71/phpcbf)
     *                      global: the tool has global access (example: phpcbf)
     *                      local: the tool was installed with composer in local (example: vendor/bin/phpcbf)
     */
    public function __construct(string $rootPath, string $toolsPath = '')
    {
        $this->name = self::FILE_NAME;
        $this->rootPath = $rootPath;

        $this->options = [
            OptionsConfiguration::EXECUTION_TAG => ExecutionMode::FULL_EXECUTION,
            OptionsConfiguration::PROCESSES_TAG => 1,
        ];

        $this->mainToolExecutablePaths  = $this->resolveToolsPath($toolsPath);

        $this->tools = [
            ToolRegistry::PHPCS,
            ToolRegistry::PHPCBF,
            ToolRegistry::PARALLEL_LINT,
            ToolRegistry::MESS_DETECTOR,
            ToolRegistry::COPYPASTE_DETECTOR,
            ToolRegistry::PHPSTAN,
            ToolRegistry::SECURITY_CHECKER,
            ToolRegistry::PHPUNIT,
            ToolRegistry::PSALM,
            ToolRegistry::SCRIPT,
        ];

        $this->configurationTools = [
            ToolRegistry::PHPCS => [
                Phpcs::EXECUTABLE_PATH_OPTION => $this->mainToolExecutablePaths . 'phpcs',
                Phpcs::PATHS => [$rootPath . '/src'],
                Phpcs::STANDARD => 'PSR12',
                Phpcs::IGNORE => [$rootPath . '/vendor'],
                Phpcs::ERROR_SEVERITY => 1,
                Phpcs::WARNING_SEVERITY => 6,
                Phpcs::CACHE => false,
                Phpcs::NO_CACHE => false,
                Phpcs::REPORT => '',
                Phpcs::PARALLEL => '',
                Phpcs::OTHER_ARGS_OPTION => '',
                Phpcs::IGNORE_ERRORS_ON_EXIT => false,
            ],

            ToolRegistry::PHPCBF => [
                Phpcbf::USE_PHPCS_CONFIGURATION => false,
                Phpcbf::EXECUTABLE_PATH_OPTION => $this->mainToolExecutablePaths . 'phpcbf',
                Phpcbf::PATHS => [$rootPath . '/src'],
                Phpcbf::STANDARD => 'PSR12',
                Phpcbf::IGNORE => [$rootPath . '/vendor'],
                Phpcbf::ERROR_SEVERITY => 1,
                Phpcbf::WARNING_SEVERITY => 6,
                Phpcbf::CACHE => false,
                Phpcbf::NO_CACHE => false,
                Phpcbf::REPORT => '',
                Phpcbf::PARALLEL => '',
                Phpcbf::OTHER_ARGS_OPTION => '',
                Phpcbf::IGNORE_ERRORS_ON_EXIT => false,
            ],

            ToolRegistry::PARALLEL_LINT => [
                ParallelLint::EXECUTABLE_PATH_OPTION => $this->vendorPath($toolsPath) . 'parallel-lint',
                ParallelLint::PATHS => [$rootPath . '/src'],
                ParallelLint::EXCLUDE => [$rootPath . '/vendor'],
                ParallelLint::OTHER_ARGS_OPTION => '--colors',
                ParallelLint::IGNORE_ERRORS_ON_EXIT => false,
            ],
            ToolRegistry::MESS_DETECTOR => [
                Phpmd::EXECUTABLE_PATH_OPTION => $this->mainToolExecutablePaths . 'phpmd',
                Phpmd::PATHS => [$rootPath . '/src'],
                Phpmd::RULES => 'unusedcode', //codesize,controversial,design,unusedcode,naming
                Phpmd::EXCLUDE => [$rootPath . '/vendor'],
                Phpmd::CACHE => false,
                Phpmd::CACHE_FILE => '',
                Phpmd::CACHE_STRATEGY => '',
                Phpmd::SUFFIXES => '',
                Phpmd::BASELINE_FILE => '',
                Phpmd::OTHER_ARGS_OPTION => '--strict',
                Phpmd::IGNORE_ERRORS_ON_EXIT => false,
            ],
            ToolRegistry::COPYPASTE_DETECTOR => [
                Phpcpd::EXECUTABLE_PATH_OPTION => $this->phpcpdPath($toolsPath) . 'phpcpd',
                Phpcpd::PATHS => [$rootPath . '/src'],
                Phpcpd::EXCLUDE => [$rootPath . '/vendor'],
                Phpcpd::MIN_LINES => 5,
                Phpcpd::MIN_TOKENS => '',
                Phpcpd::OTHER_ARGS_OPTION => '',
                Phpcpd::IGNORE_ERRORS_ON_EXIT => false,
            ],
            ToolRegistry::PHPSTAN => [
                Phpstan::EXECUTABLE_PATH_OPTION => $this->vendorPath($toolsPath) . 'phpstan',
                Phpstan::LEVEL => 0,
                Phpstan::PATHS => [$rootPath . '/src'],
                Phpstan::ERROR_FORMAT => '',
                Phpstan::NO_PROGRESS => true,
                Phpstan::CLEAR_RESULT_CACHE => false,
                Phpstan::OTHER_ARGS_OPTION => '',
                Phpstan::IGNORE_ERRORS_ON_EXIT => false,
            ],

            ToolRegistry::SECURITY_CHECKER => [
                SecurityChecker::EXECUTABLE_PATH_OPTION => $this->mainToolExecutablePaths . 'local-php-security-checker',
                SecurityChecker::OTHER_ARGS_OPTION => '-format json',
                SecurityChecker::IGNORE_ERRORS_ON_EXIT => false,
            ],

            ToolRegistry::PHPUNIT => [
                Phpunit::EXECUTABLE_PATH_OPTION => $this->vendorPath($toolsPath) . 'phpunit',
                Phpunit::GROUP => ['integration'],
                Phpunit::EXCLUDE_GROUP => ['slow'],
                Phpunit::FILTER => 'testSomething',
                Phpunit::CONFIGURATION => $rootPath . '/phpunit.xml',
                Phpunit::LOG_JUNIT => $rootPath . '/junit.xml',
                Phpunit::OTHER_ARGS_OPTION => '--colors',
                Phpunit::IGNORE_ERRORS_ON_EXIT => false,
            ],

            ToolRegistry::PSALM => [
                Psalm::PATHS => [$rootPath . '/src'],
                Psalm::EXECUTABLE_PATH_OPTION => $this->vendorPath($toolsPath) . 'psalm',
                Psalm::OTHER_ARGS_OPTION => '--no-progress',
                Psalm::IGNORE_ERRORS_ON_EXIT => false, // <--- Volver a false por defecto
                Psalm::PSALM_CONFIGURATION_FILE => $rootPath . '/qa/psalm.xml',
                Psalm::MEMORY_LIMIT => '512M',
                Psalm::THREADS => 1,
                Psalm::NO_DIFF => false,
                Psalm::OUTPUT_FORMAT => 'console',
                Psalm::PLUGIN => '',
                Psalm::USE_BASELINE => false,
                Psalm::REPORT => 'report.txt',
            ],

            ToolRegistry::SCRIPT => [
                Script::EXECUTABLE_PATH_OPTION => 'my-custom-script',
                Script::OTHER_ARGS_OPTION => '--verbose',
                Script::IGNORE_ERRORS_ON_EXIT => false,
            ],
        ];
    }

    /**
     * Builds the configuration file like an array
     *
     * @return array
     */
    public function buildArray($optionsIsNotAssociative = false): array
    {
        $file = [
            OptionsConfiguration::OPTIONS_TAG =>  $optionsIsNotAssociative ? $this->optionsIsNotAssociative() :  $this->options,
            ConfigurationFile::TOOLS => $this->tools,
        ];

        foreach ($this->configurationTools as $key => $tool) {
            $file[$key] = $tool;
        }

        return $file;
    }

    /**
     * Builds the configuration file like string with yalm format
     *
     * @return string
     */
    public function buildYaml(): string
    {
        return Yaml::dump($this->buildArray());
    }

    public function buildPhp(): string
    {
        return '<?php' . PHP_EOL . 'return ' . var_export($this->buildArray(), true) . ';';
    }

    /**
     * Creates the configuration file and saves it to the file system
     *
     * @return void
     */
    public function buildInFileSystem($path = '', bool $absolutePath = false): void
    {
        $finalPath = '';
        if (!empty($path)) {
            $finalPath = $absolutePath ? $path : "$this->rootPath/$path";
            $finalPath = rtrim($finalPath, '/');
        } else {
            $finalPath = $this->rootPath;
        }

        if (!is_dir($finalPath)) {
            mkdir($finalPath, 0777, true);
        }

        file_put_contents("$finalPath/{$this->name}", $this->buildPhp());
    }

    /**
     * Now only there one option: 'execution'. This option can be 'full', 'smart' and 'fast'.
     * No value is like value 'full' (option per default).
     *
     * @param array $options Example: ['execution' => 'fast']
     * @return ConfigurationFileBuilder
     */
    public function setOptions(array $options): ConfigurationFileBuilder
    {
        $this->options = $options;

        return $this;
    }

    public function doNotSetOptions(): ConfigurationFileBuilder
    {
        $this->options = null;

        return $this;
    }

    /**
     * Set the tools Githooks will run
     *
     * @param array $tools The possible values of this array are the tool name constants from ToolRegistry
     * @return ConfigurationFileBuilder
     */
    public function setTools(array $tools): ConfigurationFileBuilder
    {
        $this->tools = $tools;

        return $this;
    }

    /**
     * The rest of the file with the configuration of the tools
     *
     * @param array $configurationTools Each key is the name of the tool that in turn has another associative array
     * where the keys are the configuration parameters.
     * @return ConfigurationFileBuilder
     */
    public function setConfigurationTools(array $configurationTools): ConfigurationFileBuilder
    {
        $this->configurationTools = $configurationTools;

        return $this;
    }

    /**
     * Set the configuration for one tool.
     *
     * @param string $tool
     * @param array|null $configuration If null deletes the key of the tool in the configuration file.
     * @return ConfigurationFileBuilder
     */
    public function setToolConfiguration(string $tool, ?array $configuration): ConfigurationFileBuilder
    {
        if (null === $configuration) {
            unset($this->configurationTools[$tool]);
        } else {
            $this->configurationTools[$tool] = $configuration;
        }


        return $this;
    }

    public function setPhpCSConfiguration(array $configuration): ConfigurationFileBuilder
    {
        $this->configurationTools[ToolRegistry::PHPCS] = $configuration;

        return $this;
    }

    public function setPhpcbfConfiguration(array $configuration): ConfigurationFileBuilder
    {
        $this->configurationTools[ToolRegistry::PHPCBF] = $configuration;

        return $this;
    }

    public function setParallelLintConfiguration(array $configuration): ConfigurationFileBuilder
    {
        $this->configurationTools[ToolRegistry::PARALLEL_LINT] = $configuration;

        return $this;
    }

    public function setMessDetectorConfiguration(array $configuration): ConfigurationFileBuilder
    {
        $this->configurationTools[ToolRegistry::MESS_DETECTOR] = $configuration;

        return $this;
    }

    public function setPhpcpdConfiguration(array $configuration): ConfigurationFileBuilder
    {
        $this->configurationTools[ToolRegistry::COPYPASTE_DETECTOR] = $configuration;

        return $this;
    }

    public function setPhpStanConfiguration(array $configuration): ConfigurationFileBuilder
    {
        $this->configurationTools[ToolRegistry::PHPSTAN] = $configuration;

        return $this;
    }

    /**
     * Change one option of the configuration of one tool.
     *
     * @param string $toolName
     * @param array $option Format key (the option) value (the new value).
     * @return ConfigurationFileBuilder
     */
    public function changeToolOption(string $toolName, array $option): ConfigurationFileBuilder
    {
        $key = key($option);
        $this->configurationTools[$toolName][$key] = $option[$key];

        return $this;
    }

    /**
     * Sets a custom name for the script tool. Renames the config key from 'script' to the
     * given name and updates the Tools array accordingly.
     *
     * @param string $name
     * @return ConfigurationFileBuilder
     */
    public function setScriptName(string $name): ConfigurationFileBuilder
    {
        $scriptConfig = $this->configurationTools[ToolRegistry::SCRIPT];
        $scriptConfig['name'] = $name;
        $this->configurationTools[ToolRegistry::SCRIPT] = $scriptConfig;

        $key = array_search(ToolRegistry::SCRIPT, $this->tools);
        if ($key !== false) {
            $this->tools[$key] = $name;
        }

        return $this;
    }

    public function setName(string $name)
    {
        $this->name = $name;

        return $this;
    }

    protected function resolveToolsPath(string $path): string
    {
        switch ($path) {
            case self::LOCAL_TOOLS_PATH:
                return getcwd() . '/vendor/bin/';
                break;

            case self::GLOBAL_TOOLS_PATH:
                return '';
                break;

            case self::PHAR_TOOLS_PATH:
                return $this->pharExecutables();
                break;
            default:
                return $this->pharExecutables();
                break;
        }
    }

    protected function pharExecutables(): string
    {
        $path = getcwd();
        if (version_compare(phpversion(), '8.1.0', '<')) {
            $path .= '/tools/php74/';
        } else {
            $path .= '/tools/php80/';
        }
        return $path;
    }

    /**
     * Parallel-lint doesn't have phar.
     *
     * @param string $path
     * @return string
     */
    protected function vendorPath(string $path): string
    {
        switch ($path) {
            case self::GLOBAL_TOOLS_PATH:
                return '';
                break;

            case self::LOCAL_TOOLS_PATH:
                return getcwd() . '/vendor/bin/';
                break;
            default:
                return getcwd() . '/vendor/bin/';
                break;
        }
    }

    /**
     * Phpcpd can't be installed in local (venodr/bin)
     *
     * @param string $path
     * @return string
     */
    protected function phpcpdPath(string $path): string
    {
        switch ($path) {
            case self::GLOBAL_TOOLS_PATH:
                return '';
                break;

            case self::PHAR_TOOLS_PATH:
                return $this->pharExecutables();
                break;
            default:
                return $this->pharExecutables();
                break;
        }
    }

    /**
     * It is an invalid format for the Options tag
     *
     * @return array
     */
    protected function optionsIsNotAssociative(): array
    {
        $optionsIsNotAssociative = [];
        foreach ($this->options as $key => $value) {
            $optionsIsNotAssociative[] = [$key => $value];
        }
        return $optionsIsNotAssociative;
    }
}
