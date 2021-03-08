<?php

namespace Tests\System\Commands;

use phpmock\MockBuilder;
use phpmock\Mock as PhpmockMock;
use Tests\Artisan\ConsoleTestCase;

class CreateHookCommandTest extends ConsoleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->deleteDirStructure();

        $this->copyDefaultPrecommitToTestDirectory();
        mkdir($this->path . '/.git/hooks', 0777, true);
    }

    protected function copyDefaultPrecommitToTestDirectory()
    {
        mkdir($this->path . '/hooks', 0777, true);
        shell_exec("cp -r hooks " . $this->path);
    }

    protected function tearDown(): void
    {
        $this->deleteDirStructure();
    }

    /**
     * @return PhpmockMock
     */
    public function getMockRootDirectory(): PhpmockMock
    {
        $builder = new MockBuilder();
        $builder->setNamespace('GitHooks\Commands')
            ->setName('getcwd')
            ->setFunction(
                function () {
                    return $this->getPath();
                }
            );

        return $builder->build();
    }

    /** @test */
    function it_creates_default_script_for_precommit_when_is_called_without_arguments()
    {
        $mock = $this->getMockRootDirectory();
        $mock->enable();

        $this->artisan('hook')
            ->containsStringInOutput('Hook pre-commit created');

        $this->assertFileExists($this->path . '/.git/hooks/pre-commit', file_get_contents('hooks/pre-commit.php'));
        $mock->disable();
    }

    /**
     * @test
     * //FIXME Phpunit dataProviders don't work in this tests
     */
    function it_creates_default_script_in_the_hook_passed_as_argument()
    {
        $hooks = [
            'applypatch-msg' => 'applypatch-msg',
            'commit-msg' => 'commit-msg',
            'fsmonitor-watchman' => 'fsmonitor-watchman',
            'post-update' => 'post-update',
            'pre-applypatch' => 'pre-applypatch',
            'pre-commit' => 'pre-commit',
            'prepare-commit-msg' => 'prepare-commit-msg',
            'pre-push' => 'pre-push',
            'pre-rebase' => 'pre-rebase',
            'pre-receive' => 'pre-receive',
            'update' => 'update',
        ];

        $mock = $this->getMockRootDirectory();
        $mock->enable();

        foreach ($hooks as $hook) {
            $this->artisan("hook $hook")
                ->containsStringInOutput("Hook $hook created");

            $this->assertFileExists($this->path . "/.git/hooks/$hook", file_get_contents('hooks/pre-commit.php'));
        }


        $mock->disable();
    }

    //Tests
    // Establezco pre-commit por defecto
    // Establezco cualquier otro hook (o todos)
    // Establezco un script personalizado en algún hook
    // Si el hook no valida lanzo mensaje de error igual que en CleanHookCommand con los hooks soportadosdcon
}
