<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Tools\Tool;

use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;

/**
 * Class for testing purposes
 */
class ScriptFake extends Script
{
    use TestToolTrait;

    public function __construct(ToolConfiguration $toolConfiguration = null)
    {
        if (is_null($toolConfiguration)) {
            parent::__construct(new ToolConfiguration(ToolAbstract::SCRIPT, [
                'executablePath' => 'script',
            ]));
        } else {
            parent::__construct($toolConfiguration);
        }
    }
}
