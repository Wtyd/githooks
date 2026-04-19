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

    /** @test */
    public function on_job_error_matches_on_line_pattern()
    {
        $inner = $this->createMock(OutputHandler::class);

        $decorator = new GitHubActionsDecorator($inner);

        ob_start();
        $decorator->onJobError(
            'phpmd_src',
            '1s',
            'The method foo() in src/Service/Foo.php on line 42 has too many parameters.'
        );
        $output = ob_get_clean();

        $this->assertStringContainsString('::error file=src/Service/Foo.php,line=42::', $output);
    }

    /** @test */
    public function on_job_error_emits_one_annotation_per_matching_line()
    {
        $inner = $this->createMock(OutputHandler::class);

        $decorator = new GitHubActionsDecorator($inner);

        $errorOutput = implode("\n", [
            'Header without file reference',
            ' src/A.php:10 first issue',
            ' src/B.php:20 second issue',
            'Trailing noise',
        ]);

        ob_start();
        $decorator->onJobError('phpstan_src', '1s', $errorOutput);
        $output = ob_get_clean();

        $this->assertSame(2, substr_count($output, '::error '));
        $this->assertStringContainsString('::error file=src/A.php,line=10::', $output);
        $this->assertStringContainsString('::error file=src/B.php,line=20::', $output);
    }

    /** @test */
    public function on_job_error_matches_nested_paths()
    {
        $inner = $this->createMock(OutputHandler::class);

        $decorator = new GitHubActionsDecorator($inner);

        ob_start();
        $decorator->onJobError(
            'phpstan_src',
            '1s',
            ' src/Deep/Nested/Module/Service.php:123 something happened'
        );
        $output = ob_get_clean();

        $this->assertStringContainsString('::error file=src/Deep/Nested/Module/Service.php,line=123::', $output);
    }

    /** @test */
    public function on_job_error_does_not_match_non_php_files()
    {
        $inner = $this->createMock(OutputHandler::class);

        $decorator = new GitHubActionsDecorator($inner);

        ob_start();
        $decorator->onJobError(
            'phpstan_src',
            '1s',
            ' src/config.yml:10 irrelevant for code annotation'
        );
        $output = ob_get_clean();

        $this->assertStringNotContainsString('::error file=', $output);
    }
}
