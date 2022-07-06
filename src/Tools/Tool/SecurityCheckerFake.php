<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Tools\Tool;

use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;
use Wtyd\GitHooks\Tools\Tool\TestToolTrait;

/**
 * Class for testing purposes
 */
class SecurityCheckerFake extends SecurityChecker
{
    use TestToolTrait;

    public function __construct(ToolConfiguration $toolConfiguration = null)
    {
        if (is_null($toolConfiguration)) {
            parent::__construct(new ToolConfiguration(ToolAbstract::SECURITY_CHECKER, []));
        } else {
            parent::__construct($toolConfiguration);
        }
    }
}
