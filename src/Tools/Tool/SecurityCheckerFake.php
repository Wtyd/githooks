<?php

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

        $this->setOKExit();
    }

    /**
     * Override this metod
     *
     * @return void
     */
    public function execute(bool $withLiveOutput): void
    {
        //Do nothing
    }

    /**
     * Fake succefull exit
     *
     * @return SecurityCheckerFake
     */
    public function setOKExit(): SecurityCheckerFake
    {
        $this->exit = [
            'Symfony Security Check Report',
            '=============================',
            "",
            'No packages have known vulnerabilities.'
        ];
        $this->exitCode = 0;

        return $this;
    }

    /**
     * Fake wrong exit
     *
     * @return SecurityCheckerFake
     */
    public function setKOExit(): SecurityCheckerFake
    {
        $this->exit = ['Some error for testing'];
        $this->exitCode = 1;

        return $this;
    }
}
