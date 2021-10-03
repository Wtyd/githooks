<?php

namespace Tests\Utils;

use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;
use Wtyd\GitHooks\Tools\SecurityChecker;
use Wtyd\GitHooks\Tools\ToolAbstract;

/**
 * This tool cannot be runned in testing environment
 */
class SecurityCheckerFake extends SecurityChecker
{
    public function __construct()
    {
        parent::__construct(new ToolConfiguration(ToolAbstract::SECURITY_CHECKER, []));
        $this->setOKExit();
    }
    /**
     * Override this metod
     *
     * @return void
     */
    public function execute()
    {
        //Do nothing
    }

    /**
     * Override this metod
     *
     * @return void
     */
    public function executeWithLiveOutput()
    {
        //Do nothing
    }

    /**
     * Fake succefull exit
     *
     * @return void
     */
    public function setOKExit()
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
     * @return void
     */
    public function setKOExit()
    {
        $this->exit = ['Some error for testing'];
        $this->exitCode = 1;

        return $this;
    }
}
