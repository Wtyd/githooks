<?php

namespace App\Commands\Tools;

use Wtyd\GitHooks\Tools\Errors;
use Wtyd\GitHooks\Tools\ToolExecutor;
use Wtyd\GitHooks\Tools\ToolsPreparer;
use LaravelZero\Framework\Commands\Command;

abstract class ToolCommand extends Command
{
    /**
     * @var  ToolsPreparer
     */
    protected $toolsPreparer;

    /**
     * @var ToolExecutor
     */
    protected $toolExecutor;

    public function __construct(ToolsPreparer $toolsPreparer, ToolExecutor $toolExecutor)
    {
        $this->toolsPreparer = $toolsPreparer;
        $this->toolExecutor = $toolExecutor;
        parent::__construct();
    }

    protected function exit(Errors $errors): int
    {
        return $errors->isEmpty() ? 0 : 1;
    }
}
