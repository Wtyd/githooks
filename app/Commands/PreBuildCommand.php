<?php

namespace Wtyd\GitHooks\App\Commands;

use Wtyd\GitHooks\Utils\Printer;
use LaravelZero\Framework\Commands\Command;
use Wtyd\GitHooks\Build\ManageDependencies;

class PreBuildCommand extends Command
{
    protected $signature = 'app:pre-build';
    protected $description = 'Prepares the app dependencies for the build processs';

    /**
     * Extra information about the command invoked with the --help flag.
     *
     * @var string
     */
    protected $help = 'Without arguments deletes the pre-commit hook. A optional argument can be the name of another hook. Example: hook:clean pre-push.';

    /**
     * @var Printer
     */
    protected $printer;

    /** @var ManageDependencies */
    private $manageDependencies;

    public function __construct(ManageDependencies $manageDependencies)
    {
        parent::__construct();
        $this->manageDependencies = $manageDependencies;
    }
    public function handle()
    {
        $this->title('Deleting dev dependencies');
        $this->manageDependencies->deleteDevDependencies();

        $this->title('Updating prod dependencies');
        $this->manageDependencies->updateProdDependencies();
    }
}
