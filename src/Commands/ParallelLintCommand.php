<?php
namespace GitHooks\Commands;

use GitHooks\Constants;
use Illuminate\Console\Command;

class ParallelLintCommand extends Command
{
    protected $signature = 'tool:parallel-lint';
    protected $description = 'Ejecuta la herramienta de validación de sintaxis parallel-lint con la configuración extraída del fichero de configuración.';
    protected $toolCommandExecutor;

    public function __construct(ToolCommandExecutor $toolCommandExecutor)
    {
        $this->toolCommandExecutor = $toolCommandExecutor;
        parent::__construct();
    }

    public function handle()
    {
        $this->toolCommandExecutor->execute(Constants::PARALLEL_LINT);
    }
}
