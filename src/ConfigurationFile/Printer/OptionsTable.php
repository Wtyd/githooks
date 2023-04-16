<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\ConfigurationFile\Printer;

use Wtyd\GitHooks\ConfigurationFile\ConfigurationFile;
use Wtyd\GitHooks\ConfigurationFile\OptionsConfiguration;

class OptionsTable extends TableAbstract
{
    public function __construct(ConfigurationFile $configurationFile)
    {
        $this->headers = [OptionsConfiguration::OPTIONS_TAG, 'Values'];

        $values = [
            $configurationFile->isDefaultExecution() ? $configurationFile->getExecution() . ' (default)' :
            $configurationFile->getExecution(),
            $configurationFile->isDefaultProcesses() ? $configurationFile->getProcesses() . ' (default)' : $configurationFile->getProcesses(),
        ];
        $this->rows = array_map(function ($options, $other) {
                return [$options, $other];
        }, OptionsConfiguration::TAGS_OPTIONS_TAG, $values);
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getRows(): array
    {
        return $this->rows;
    }
}
