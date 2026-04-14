<?php

declare(strict_types=1);

namespace Tests\Unit\Output\CI;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Output\CI\GitLabCIDecorator;
use Wtyd\GitHooks\Output\OutputHandler;

class GitLabCIDecoratorTest extends TestCase
{
    /** @test */
    public function on_job_start_emits_section_start_and_delegates()
    {
        $inner = $this->createMock(OutputHandler::class);
        $inner->expects($this->once())->method('onJobStart')->with('phpstan_src');

        $decorator = new GitLabCIDecorator($inner);

        ob_start();
        $decorator->onJobStart('phpstan_src');
        $output = ob_get_clean();

        $this->assertStringContainsString('section_start:', $output);
        $this->assertStringContainsString('[collapsed=true]', $output);
        $this->assertStringContainsString('phpstan_src', $output);
    }

    /** @test */
    public function on_job_success_delegates_and_emits_section_end()
    {
        $inner = $this->createMock(OutputHandler::class);
        $inner->expects($this->once())->method('onJobStart');
        $inner->expects($this->once())->method('onJobSuccess')->with('phpstan_src', '1.23s');

        $decorator = new GitLabCIDecorator($inner);

        // Need to call onJobStart first to set section ID
        ob_start();
        $decorator->onJobStart('phpstan_src');
        ob_end_clean();

        ob_start();
        $decorator->onJobSuccess('phpstan_src', '1.23s');
        $output = ob_get_clean();

        $this->assertStringContainsString('section_end:', $output);
    }

    /** @test */
    public function on_job_error_delegates_and_emits_section_end()
    {
        $inner = $this->createMock(OutputHandler::class);
        $inner->expects($this->once())->method('onJobStart');
        $inner->expects($this->once())->method('onJobError');

        $decorator = new GitLabCIDecorator($inner);

        ob_start();
        $decorator->onJobStart('phpstan_src');
        ob_end_clean();

        ob_start();
        $decorator->onJobError('phpstan_src', '500ms', 'error output');
        $output = ob_get_clean();

        $this->assertStringContainsString('section_end:', $output);
    }

    /** @test */
    public function section_ids_increment()
    {
        $inner = $this->createMock(OutputHandler::class);
        $decorator = new GitLabCIDecorator($inner);

        ob_start();
        $decorator->onJobStart('job1');
        $output1 = ob_get_clean();

        ob_start();
        $decorator->onJobStart('job2');
        $output2 = ob_get_clean();

        $this->assertStringContainsString('githooks_job_1', $output1);
        $this->assertStringContainsString('githooks_job_2', $output2);
    }

    /** @test */
    public function it_delegates_all_other_methods()
    {
        $inner = $this->createMock(OutputHandler::class);
        $inner->expects($this->once())->method('onFlowStart')->with(3);
        $inner->expects($this->once())->method('onJobSkipped');
        $inner->expects($this->once())->method('flush');

        $decorator = new GitLabCIDecorator($inner);
        $decorator->onFlowStart(3);
        $decorator->onJobSkipped('phpcs', 'reason');
        $decorator->flush();
    }
}
