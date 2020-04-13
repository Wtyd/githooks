<?php
namespace GitHooks\Commands;

use GitHooks\Constants;
use Illuminate\Console\Command;

class CopyPasteDetectorCommand extends Command
{
    protected $signature = 'tool:phpcpd';
    protected $description = 'Ejecuta la herramienta de validación de código duplicado phpcpd con la configuración extraída del fichero de configuración.';
    protected $toolCommandExecutor;

    public function __construct(ToolCommandExecutor $toolCommandExecutor)
    {
        $this->toolCommandExecutor = $toolCommandExecutor;
        parent::__construct();
    }

    public function handle()
    {
        $this->toolCommandExecutor->execute(Constants::COPYPASTE_DETECTOR);
    }
}
