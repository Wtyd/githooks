<?php

namespace Tests\Utils;

use Wtyd\GitHooks\Tools\Tool\{
    Phpcpd,
    SecurityChecker,
    ParallelLint,
    Phpmd,
    Phpstan,
    ToolAbstract
};
use Wtyd\GitHooks\Tools\Tool\CodeSniffer\{
    Phpcs,
    Phpcbf
};
use Symfony\Component\Yaml\Yaml;
use Wtyd\GitHooks\ConfigurationFile\ConfigurationFile;
use Wtyd\GitHooks\ConfigurationFile\OptionsConfiguration;
use Wtyd\GitHooks\LoadTools\ExecutionMode;

/**
 * Facilitates the creation of custom 'githooks.yml' configuration files for testing.
 * By default prepares a full configuration file. After, you can change each part of the file with the 'set' methods.
 * Finally you must 'build' the file:
 *      1. build method: build the file on array associative format.
 *      2. buildYalm: build the file on yalm format.
 */
class ConfigurationFileBuilder
{
    public const PHAR_TOOLS_PATH = 'phar';
    public const GLOBAL_TOOLS_PATH = 'global';
    public const LOCAL_TOOLS_PATH = 'local';

    protected $options;

    protected $tools;

    protected $configurationTools;

    protected $executablesPath;

    /**
     * Set the attributes with default values.
     *
     * @param string $rootPath Customize what path you would as project root
     * @param string $toolsPath The way to find the executables of the tools
     *                      phar: the full path to the executables (example: tools/php71/Phpcbf)
     *                      global: the tool has global acces (example: Phpcbf)
     *                      local: the tool was installed with composer in local (example: vendor/bin/Phpcbf)
     */
    public function __construct(string $rootPath, string $toolsPath = '')
    {
        $this->options = [OptionsConfiguration::EXECUTION_TAG => ExecutionMode::FULL_EXECUTION];

        $this->mainToolExecutablePaths  = $this->resolveToolsPath($toolsPath);

        $this->tools = [
            ToolAbstract::PHPCS,
            ToolAbstract::PHPCBF,
            ToolAbstract::PARALLEL_LINT,
            ToolAbstract::MESS_DETECTOR,
            ToolAbstract::COPYPASTE_DETECTOR,
            ToolAbstract::PHPSTAN,
            ToolAbstract::SECURITY_CHECKER,
        ];

        $this->configurationTools = [
            ToolAbstract::PHPCS => [
                Phpcs::EXECUTABLE_PATH_OPTION => $this->mainToolExecutablePaths . 'phpcs',
                Phpcs::PATHS => [$rootPath . '/src'],
                Phpcs::STANDARD => 'PSR12',
                Phpcs::IGNORE => [$rootPath . '/vendor'],
                Phpcs::ERROR_SEVERITY => 1,
                Phpcs::WARNING_SEVERITY => 6
            ],

            ToolAbstract::PHPCBF => [
                Phpcbf::EXECUTABLE_PATH_OPTION => $this->mainToolExecutablePaths . 'phpcbf',
                Phpcbf::PATHS => [$rootPath . '/src'],
                Phpcbf::STANDARD => 'PSR12',
                Phpcbf::IGNORE => [$rootPath . '/vendor'],
                Phpcbf::ERROR_SEVERITY => 1,
                Phpcbf::WARNING_SEVERITY => 6
            ],

            ToolAbstract::PARALLEL_LINT => [
                ParallelLint::EXECUTABLE_PATH_OPTION => $this->parallelLintPath($toolsPath) . 'parallel-lint',
                ParallelLint::PATHS => [$rootPath . '/src'],
                ParallelLint::EXCLUDE => [$rootPath . '/vendor'],
                ParallelLint::OTHER_ARGS_OPTION => '--colors',
            ],
            ToolAbstract::MESS_DETECTOR => [
                Phpmd::EXECUTABLE_PATH_OPTION => $this->mainToolExecutablePaths . 'phpmd',
                Phpmd::PATHS => [$rootPath . '/src'],
                Phpmd::RULES => 'unusedcode', //codesize,controversial,design,unusedcode,naming
                Phpmd::EXCLUDE => [$rootPath . '/vendor']
            ],
            ToolAbstract::COPYPASTE_DETECTOR => [
                Phpcpd::EXECUTABLE_PATH_OPTION => $this->phpcpdPath($toolsPath) . 'phpcpd',
                Phpcpd::PATHS => [$rootPath . '/src'],
                Phpcpd::EXCLUDE => [$rootPath . '/vendor'],
                Phpcpd::OTHER_ARGS_OPTION => '--min-lines=5',
            ],
            ToolAbstract::PHPSTAN => [
                Phpstan::EXECUTABLE_PATH_OPTION => $this->mainToolExecutablePaths . 'phpstan',
                Phpstan::LEVEL => 0,
                Phpstan::PATHS => [$rootPath . '/src'],
                Phpstan::OTHER_ARGS_OPTION => '--no-progress',
            ],

            ToolAbstract::SECURITY_CHECKER => [
                SecurityChecker::EXECUTABLE_PATH_OPTION => $this->mainToolExecutablePaths . 'local-php-security-checker',
            ],
        ];
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
        if (version_compare(phpversion(), '7.3.0', '<')) {
            $path .= '/tools/php71/';
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
    protected function parallelLintPath(string $path): string
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
     * Builds the configuration file like an array
     *
     * @return array
     */
    public function buildArray(): array
    {
        $file = [
            OptionsConfiguration::OPTIONS_TAG => $this->options,
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
    public function buildYalm(): string
    {
        return Yaml::dump($this->buildArray());
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

    /**
     * Set the tools Githooks will run
     *
     * @param array $tools The possible values of this array are the keys of the ToolAbstract::SUPPORTED_TOOLS array
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
     * @param array $configurationTools Each key is the name of the tool that in turn has another associative array where the keys
     *  are the configuration parameters.
     * @return ConfigurationFileBuilder
     */
    public function setConfigurationTools(array $configurationTools): ConfigurationFileBuilder
    {
        $this->configurationTools = $configurationTools;

        return $this;
    }

    public function setPhpCSConfiguration(array $configuration): ConfigurationFileBuilder
    {
        $this->configurationTools[ToolAbstract::PHPCS] = $configuration;

        return $this;
    }

    public function setPhpcbfConfiguration(array $configuration): ConfigurationFileBuilder
    {
        $this->configurationTools[ToolAbstract::PHPCBF] = $configuration;

        return $this;
    }

    public function setParallelLintConfiguration(array $configuration): ConfigurationFileBuilder
    {
        $this->configurationTools[ToolAbstract::PARALLEL_LINT] = $configuration;

        return $this;
    }

    public function setMessDetectorConfiguration(array $configuration): ConfigurationFileBuilder
    {
        $this->configurationTools[ToolAbstract::MESS_DETECTOR] = $configuration;

        return $this;
    }

    public function setPhpcpdConfiguration(array $configuration): ConfigurationFileBuilder
    {
        $this->configurationTools[ToolAbstract::COPYPASTE_DETECTOR] = $configuration;

        return $this;
    }

    public function setPhpStanConfiguration(array $configuration): ConfigurationFileBuilder
    {
        $this->configurationTools[ToolAbstract::PHPSTAN] = $configuration;

        return $this;
    }
}
