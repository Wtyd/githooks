<?php

namespace Tests\System;

use GitHooks\Constants;
use GitHooks\Tools\{
    CodeSniffer,
    CopyPasteDetector,
    MessDetector,
    ParallelLint,
    Stan
};
use Symfony\Component\Yaml\Yaml;

class ConfigurationFileBuilder
{
    protected $options;

    protected $tools;

    protected $configurationTools;

    public function __construct(string $path)
    {
        $this->options = [Constants::SMART_EXECUTION => false];

        $this->tools = [
            Constants::CODE_SNIFFER,
            Constants::PARALLEL_LINT,
            Constants::MESS_DETECTOR,
            Constants::COPYPASTE_DETECTOR,
            Constants::PHPSTAN,
            Constants::CHECK_SECURITY,
        ];

        $this->configurationTools = [
            Constants::CODE_SNIFFER => [
                CodeSniffer::PATHS => [$path . '/src'],
                CodeSniffer::STANDARD => 'PSR12',
                CodeSniffer::IGNORE => [$path . 'vendor'],
                CodeSniffer::ERROR_SEVERITY => 1,
                CodeSniffer::WARNING_SEVERITY => 6
            ],

            Constants::PARALLEL_LINT => [
                ParallelLint::PATHS => [$path . '/src'],
                ParallelLint::EXCLUDE => [$path . '/vendor']
            ],
            Constants::MESS_DETECTOR => [
                MessDetector::PATHS => [$path . '/src'],
                MessDetector::RULES => 'unusedcode', //codesize,controversial,design,unusedcode,naming
                MessDetector::EXCLUDE => [$path . '/vendor']
            ],
            Constants::COPYPASTE_DETECTOR => [
                CopyPasteDetector::PATHS => [$path . '/src'],
                CopyPasteDetector::EXCLUDE => [$path . '/vendor']
            ],
            Constants::PHPSTAN => [
                // Stan::PHPSTAN_CONFIGURATION_FILE =>,
                Stan::LEVEL => 0,
                // Stan::MEMORY_LIMIT =>,
                Stan::PATHS => [$path . '/src']
            ],
        ];
    }

    public function build()
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

    public function buildYalm()
    {
        return Yaml::dump($this->build());
    }

    /**
     * El único valor que admite ahora mismo es el booleano "smartExecution" que
     *
     * @param array $options ['smartExecution' => true]
     * @return ConfigurationFileBuilder
     */
    public function setOptions(array $options): ConfigurationFileBuilder
    {
        $this->options = $options;

        return $this;
    }

    /**
     * Undocumented function
     *
     * @param array $tools Puede contener los valores de las keys de Constants::TOOL_LIST
     * @return ConfigurationFileBuilder
     */
    public function setTools(array $tools): ConfigurationFileBuilder
    {
        $this->tools = $tools;

        return $this;
    }

    /**
     * El resto del fichero con la configuración de las herramientas
     *
     * @param array $configurationTools Cada key es el nombre de la herramienta que a su vez tiene otro array asociativo donde las keys
     * son los parámetros de configuracion.
     * @return ConfigurationFileBuilder
     */
    public function setConfigurationTools(array $configurationTools): ConfigurationFileBuilder
    {
        $this->configurationTools = $configurationTools;

        return $this;
    }

    public function setPhpCSConfiguration(array $configuration): ConfigurationFileBuilder
    {
        $this->configurationTools[Constants::CODE_SNIFFER] = $configuration;

        return $this;
    }

    public function setParallelLintConfiguration(array $configuration): ConfigurationFileBuilder
    {
        $this->configurationTools[Constants::PARALLEL_LINT] = $configuration;

        return $this;
    }

    public function setMessDetectorConfiguration(array $configuration): ConfigurationFileBuilder
    {
        $this->configurationTools[Constants::MESS_DETECTOR] = $configuration;

        return $this;
    }

    public function setCopyPasteDetectorConfiguration(array $configuration): ConfigurationFileBuilder
    {
        $this->configurationTools[Constants::COPYPASTE_DETECTOR] = $configuration;

        return $this;
    }

    public function setPhpStanConfiguration(array $configuration): ConfigurationFileBuilder
    {
        $this->configurationTools[Constants::PHPSTAN] = $configuration;

        return $this;
    }
}
