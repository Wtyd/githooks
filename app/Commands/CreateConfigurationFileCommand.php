<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;
use Wtyd\GitHooks\Utils\Printer;

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
        try {
            if (copy($origin, $destiny) === false) {
                $this->printer->error("Failed to copy $origin to $destiny");
            } else {
                $this->printer->success('Configuration file githooks.yml has been created in root path');
            }
        } catch (\Throwable $th) {
            $this->printer->error("Failed to copy $origin to $destiny");
        }
    }
}
