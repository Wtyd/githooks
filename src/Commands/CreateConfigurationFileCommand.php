<?php

namespace GitHooks\Commands;

use Illuminate\Console\Command;
use GitHooks\Utils\Printer;

class CreateConfigurationFileCommand extends Command
{
    protected $signature = 'conf:init';
    protected $description = 'Creates the configuration file githooks.yml in the project path';

    /**
     * @var Printer
     */
    protected $printer;

    public function __construct(Printer $printer)
    {
        $this->printer = $printer;
        parent::__construct();
    }

    public function handle()
    {
        $root = getcwd();
        $origin = "$root/vendor/wtyd/githooks/qa/githooks.dist.yml";

        $destiny = "$root/githooks.yml";

        $this->copyFile($origin, $destiny);
    }

    protected function copyFile(string $origin, string $destiny): void
    {
        if (copy($origin, $destiny) === false) {
            $this->printer->error("Error copying $origin to $destiny");
        } else {
            $this->printer->success('Configuration file githooks.yml created in root path');
        }
    }
}
