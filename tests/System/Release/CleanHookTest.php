<?php

namespace Tests\System\Release;

use Tests\ConsoleTestCase;
use Tests\ReleaseTestCase;

/**
 * @group release
 */
class CleanHookTest extends ReleaseTestCase
{
    /** @test */
    function it_cleans_a_githook_and_returns_exit_0()
    {
        file_put_contents(
            '.git/hooks/pre-push',
            $this->phpFileBuilder->build()
        );

        passthru("$this->githooks hook:clean pre-push", $exitCode);

        $this->assertStringContainsString('Hook pre-push has been deleted', $this->getActualOutput());
        $this->assertEquals(0, $exitCode);
        $this->assertFileDoesNotExist('.git/hooks/pre-push');
    }

    /** @test */
    function it_returns_exit_1_when_it_cannot_delete_the_hook()
    {
        passthru("$this->githooks hook:clean no-valid-hook", $exitCode);

        $this->assertStringContainsString("'no-valid-hook' is not a valid git hook. Avaliable hooks are:", $this->getActualOutput());
        $this->assertEquals(1, $exitCode);
        $this->assertFileDoesNotExist('.git/hooks/pre-push');
    }
}
