<?php

namespace GitHooks\Commands;

use Illuminate\Console\Command;
use GitHooks\Utils\Printer;

class CreateConfigurationFileCommand extends Command
{
    protected $signature = 'conf:init';
    protected $description = 'Creates the configuration file githooks.yml in the project path and copies the githook precommit';
    /**
     * @var Printer
     */
    protected $printer;

    public function __construct(Printer $printer)
    {
        $this->printer = $printer;
        parent::__construct();
    }
    //TODO Comando para copiar un fichero que ejecute githooks desde los eventos de git. El evento se pasará mediante parámetro.
    public function handle()
    {
        $root = getcwd();
        $origen = "$root/vendor/zataca/githooks/qa/githooks.yml";

        $destino = "$root/qa/githooks.yml";

        if (!is_dir("$root/qa")) {
            mkdir("$root/qa");
        }

        $this->copiarFichero($origen, $destino);

        // $this->line('Display this on the screen en blanco');
        // $this->info('Display this on the screen en verde');
        // $this->comment('Hello World en amarillo');
        // $this->error('Something went wrong! en rojo');
        // $this->confirm('Do you wish to continue?');
        // $name = $this->ask('What is your name?');
        // $password = $this->secret('What is the password?');
        // $this->comment("Mi nombre es $name");
        // $this->comment("Mi password es $password");
    }

    protected function copiarFichero(string $origen, string $destino): void
    {
        if (copy($origen, $destino) === false) {
            $this->printer->error("Error al copiar $origen en $destino");
        } else {
            if (chmod($destino, 0755) === false) {
                $this->printer->error('Error al dar permisos al fichero');
            } else {
                $this->printer->info('Fichero de configuración githooks.yml creado en la carpeta ./qa');
            }
        }
    }
}
