<?php

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * @group release
 */
class CreateHookTest extends ReleaseTestCase
{
    /** @test */
    function it_sets_a_custom_githook_and_returns_exit_code_0()
    {
        $scriptFile = self::TESTS_PATH . '/src/File.php';
        file_put_contents(
            $scriptFile,
            $this->phpFileBuilder->setFileName('FileWithErrors')->buildWithErrors(['phpcs', 'parallel-lint', 'phpmd', 'phpcpd', 'phpstan'])
        );

        passthru("$this->githooks hook pre-push $scriptFile", $exitCode);

        $this->assertStringContainsString('Hook pre-push created', $this->getActualOutput());
        $this->assertEquals(0, $exitCode);
        $this->assertFileEquals($scriptFile, '.git/hooks/pre-push');
    }

    /** @test */
    function it_returns_exit_code_1_when_cannot_set_the_githook()
    {
        $scriptFile = self::TESTS_PATH . '/src/File.php';

        passthru("$this->githooks hook pre-push $scriptFile", $exitCode);

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString("$scriptFile file not found", $this->getActualOutput());
    }
}
