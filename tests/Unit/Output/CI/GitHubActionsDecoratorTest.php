<?php

declare(strict_types=1);

namespace Tests\Unit\Output\CI;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Output\CI\GitHubActionsDecorator;
use Wtyd\GitHooks\Output\OutputHandler;

class GitHubActionsDecoratorTest extends TestCase
{
    /** @test */
    public function on_job_start_emits_group_and_delegates()
    {
        $inner = $this->createMock(OutputHandler::class);
        $inner->expects($this->once())->method('onJobStart')->with('phpstan_src');

        $decorator = new GitHubActionsDecorator($inner);

        ob_start();
        $decorator->onJobStart('phpstan_src');
        $output = ob_get_clean();

        $this->assertStringContainsString('::group::phpstan_src', $output);
    }

    /** @test */
    public function on_job_success_delegates_and_emits_endgroup()
    {
        $inner = $this->createMock(OutputHandler::class);
        $inner->expects($this->once())->method('onJobSuccess')->with('phpstan_src', '1.23s');

        $decorator = new GitHubActionsDecorator($inner);

        ob_start();
        $decorator->onJobSuccess('phpstan_src', '1.23s');
        $output = ob_get_clean();

        $this->assertStringContainsString('::endgroup::', $output);
    }

    /** @test */
    public function on_job_error_emits_annotations_and_endgroup()
    {
        $inner = $this->createMock(OutputHandler::class);
        $inner->expects($this->once())->method('onJobError');

        $errorOutput = "Error in src/User.php:14 - Method not found\nAnother line";

        $decorator = new GitHubActionsDecorator($inner);

        ob_start();
        $decorator->onJobError('phpstan_src', '500ms', $errorOutput);
        $output = ob_get_clean();

        $this->assertStringContainsString('::error file=src/User.php,line=14::', $output);
        $this->assertStringContainsString('::endgroup::', $output);
    }

    /** @test */
    public function on_job_error_without_file_pattern_emits_only_endgroup()
    {
        $inner = $this->createMock(OutputHandler::class);
        $inner->expects($this->once())->method('onJobError');

        $decorator = new GitHubActionsDecorator($inner);

        ob_start();
        $decorator->onJobError('phpunit', '2s', 'General failure without file references');
        $output = ob_get_clean();

        $this->assertStringNotContainsString('::error', $output);
        $this->assertStringContainsString('::endgroup::', $output);
    }

    /** @test */
    public function it_delegates_all_other_methods()
    {
        $inner = $this->createMock(OutputHandler::class);
        $inner->expects($this->once())->method('onFlowStart')->with(5);
        $inner->expects($this->once())->method('onJobSkipped')->with('phpcs', 'reason');
        $inner->expects($this->once())->method('flush');

        $decorator = new GitHubActionsDecorator($inner);
        $decorator->onFlowStart(5);
        $decorator->onJobSkipped('phpcs', 'reason');
        $decorator->flush();
    }
}
