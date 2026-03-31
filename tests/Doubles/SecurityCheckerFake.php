<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;
use Wtyd\GitHooks\Registry\ToolRegistry;
use Wtyd\GitHooks\Tools\Tool\SecurityChecker;

/**
 * Class for testing purposes
 */
class SecurityCheckerFake extends SecurityChecker
{
    use TestToolTrait;

    public function __construct(ToolConfiguration $toolConfiguration = null)
    {
        if (is_null($toolConfiguration)) {
            $registry = new ToolRegistry();
            parent::__construct(new ToolConfiguration(ToolRegistry::SECURITY_CHECKER, [], $registry));
        } else {
            parent::__construct($toolConfiguration);
        }
    }
}
