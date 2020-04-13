<?php
namespace GitHooks\Commands;

use GitHooks\Constants;
use Illuminate\Console\Command;

class CodeSnifferCommand extends Command
{
    protected $signature = 'tool:phpcs';
    protected $description = 'Ejecuta la herramienta de formateado de código phpcs con la configuración extraída del fichero de configuración.';
    protected $toolCommandExecutor;

    public function __construct(ToolCommandExecutor $toolCommandExecutor)
    {
        $this->toolCommandExecutor = $toolCommandExecutor;
        parent::__construct();
    }

    public function handle()
    {
        $this->toolCommandExecutor->execute(Constants::CODE_SNIFFER);
    }
}
