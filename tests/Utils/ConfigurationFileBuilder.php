<?php

namespace Tests\Utils;

use Wtyd\GitHooks\Constants;
use Wtyd\GitHooks\Tools\{
    CodeSniffer,
    CopyPasteDetector,
    MessDetector,
    ParallelLint,
    Stan,
    ToolAbstract
};
use Symfony\Component\Yaml\Yaml;

/**
 * Facilitates the creation of custom 'githooks.yml' configuration files for testing.
 * By default prepares a full configuration file. After, you can change each part of the file with the 'set' methods.
 * Finally you must 'build' the file:
 *      1. build method: build the file on array associative format.
 *      2. buildYalm: build the file on yalm format.
 */
class ConfigurationFileBuilder
{
    protected $options;

    protected $tools;

    protected $configurationTools;

    /**
     * Set the attributes with default values.
     *
     * @param string $rootPath Customize what path you would as project root
     */
    public function __construct(string $rootPath)
    {
        $this->options = [Constants::EXECUTION => Constants::FULL_EXECUTION];

        $this->tools = [
            ToolAbstract::CODE_SNIFFER,
            ToolAbstract::PARALLEL_LINT,
            ToolAbstract::MESS_DETECTOR,
            ToolAbstract::COPYPASTE_DETECTOR,
            ToolAbstract::PHPSTAN,
            ToolAbstract::CHECK_SECURITY,
        ];

        $this->configurationTools = [
            ToolAbstract::CODE_SNIFFER => [
                CodeSniffer::PATHS => [$rootPath . '/src'],
                CodeSniffer::STANDARD => 'PSR12',
                CodeSniffer::IGNORE => [$rootPath . '/vendor'],
                CodeSniffer::ERROR_SEVERITY => 1,
                CodeSniffer::WARNING_SEVERITY => 6
            ],

            ToolAbstract::PARALLEL_LINT => [
                ParallelLint::PATHS => [$rootPath . '/src'],
                ParallelLint::EXCLUDE => [$rootPath . '/vendor']
            ],
            ToolAbstract::MESS_DETECTOR => [
                MessDetector::PATHS => [$rootPath . '/src'],
                MessDetector::RULES => 'unusedcode', //codesize,controversial,design,unusedcode,naming
                MessDetector::EXCLUDE => [$rootPath . '/vendor']
            ],
            ToolAbstract::COPYPASTE_DETECTOR => [
                CopyPasteDetector::PATHS => [$rootPath . '/src'],
                CopyPasteDetector::EXCLUDE => [$rootPath . '/vendor']
            ],
            ToolAbstract::PHPSTAN => [
                Stan::LEVEL => 0,
                Stan::PATHS => [$rootPath . '/src']
            ],
        ];
    }

    /**
     * Builds the configuration file like an array
     *
     * @return array
     */
    public function buildArray(): array
    {
        $file = [
            Constants::OPTIONS => $this->options,
            Constants::TOOLS => $this->tools,
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
        $this->configurationTools[ToolAbstract::CODE_SNIFFER] = $configuration;

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

    public function setCopyPasteDetectorConfiguration(array $configuration): ConfigurationFileBuilder
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
