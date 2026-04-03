<?php

declare(strict_types=1);

namespace Tests\Unit\Hooks;

use PHPUnit\Framework\TestCase;
use Tests\Doubles\FileUtilsFake;
use Wtyd\GitHooks\Configuration\ConfigurationResult;
use Wtyd\GitHooks\Configuration\HookConfiguration;
use Wtyd\GitHooks\Configuration\HookRef;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Configuration\ValidationResult;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowPreparer;
use Wtyd\GitHooks\Execution\FlowResult;
use Wtyd\GitHooks\Hooks\HookRunner;

class HookRunnerTest extends TestCase
{
    private FileUtilsFake $fileUtils;

    /** @var FlowPreparer&\PHPUnit\Framework\MockObject\MockObject */
    private $preparer;

    /** @var FlowExecutor&\PHPUnit\Framework\MockObject\MockObject */
    private $executor;

    private HookRunner $runner;

    protected function setUp(): void
    {
        $this->fileUtils = new FileUtilsFake();
        $this->preparer = $this->createMock(FlowPreparer::class);
        $this->executor = $this->createMock(FlowExecutor::class);
        $this->runner = new HookRunner($this->preparer, $this->executor, $this->fileUtils);
    }

    /** @test */
    public function only_files_without_exclude_files_works_as_before()
    {
        $this->fileUtils->setModifiedfiles(['src/User.php']);

        $config = $this->buildConfigWithJobRef('phpcs', ['src/*.php'], []);

        $this->executor->expects($this->once())
            ->method('execute')
            ->willReturn(new FlowResult('phpcs', [], '0.00s'));

        $results = $this->runner->run('pre-commit', $config);

        $this->assertCount(1, $results);
    }

    /** @test */
    public function only_files_skips_when_no_files_match()
    {
        $this->fileUtils->setModifiedfiles(['vendor/autoload.php']);

        $config = $this->buildConfigWithJobRef('phpcs', ['src/*.php'], []);

        $this->executor->expects($this->never())->method('execute');

        $results = $this->runner->run('pre-commit', $config);

        $this->assertEmpty($results);
    }

    /** @test */
    public function exclude_files_prevents_execution_when_all_matching_files_are_excluded()
    {
        $this->fileUtils->setModifiedfiles(['src/Runner.php']);

        $config = $this->buildConfigWithJobRef('phpcs', ['src/*.php'], ['src/Runner.php']);

        $this->executor->expects($this->never())->method('execute');

        $results = $this->runner->run('pre-commit', $config);

        $this->assertEmpty($results);
    }

    /** @test */
    public function exclude_files_allows_execution_when_non_excluded_files_match()
    {
        $this->fileUtils->setModifiedfiles([
            'src/Runner.php',
            'src/User.php',
        ]);

        $config = $this->buildConfigWithJobRef('phpcs', ['src/*.php'], ['src/Runner.php']);

        $this->executor->expects($this->once())
            ->method('execute')
            ->willReturn(new FlowResult('phpcs', [], '0.00s'));

        $results = $this->runner->run('pre-commit', $config);

        $this->assertCount(1, $results);
    }

    /** @test */
    public function exclude_files_without_only_files_excludes_from_all()
    {
        $this->fileUtils->setModifiedfiles(['vendor/autoload.php']);

        $config = $this->buildConfigWithJobRef('phpcs', [], ['vendor/*.php']);

        $this->executor->expects($this->never())->method('execute');

        $results = $this->runner->run('pre-commit', $config);

        $this->assertEmpty($results);
    }

    /** @test */
    public function exclude_files_without_only_files_allows_non_excluded()
    {
        $this->fileUtils->setModifiedfiles(['src/User.php']);

        $config = $this->buildConfigWithJobRef('phpcs', [], ['vendor/*.php']);

        $this->executor->expects($this->once())
            ->method('execute')
            ->willReturn(new FlowResult('phpcs', [], '0.00s'));

        $results = $this->runner->run('pre-commit', $config);

        $this->assertCount(1, $results);
    }

    /** @test */
    public function exclude_prevails_over_include_for_same_pattern()
    {
        $this->fileUtils->setModifiedfiles(['src/User.php']);

        $config = $this->buildConfigWithJobRef('phpcs', ['src/*.php'], ['src/*.php']);

        $this->executor->expects($this->never())->method('execute');

        $results = $this->runner->run('pre-commit', $config);

        $this->assertEmpty($results);
    }

    /** @test */
    public function no_conditions_always_executes()
    {
        $this->fileUtils->setModifiedfiles(['anything.txt']);

        $config = $this->buildConfigWithJobRef('phpcs', [], []);

        $this->executor->expects($this->once())
            ->method('execute')
            ->willReturn(new FlowResult('phpcs', [], '0.00s'));

        $results = $this->runner->run('pre-commit', $config);

        $this->assertCount(1, $results);
    }

    /** @test */
    public function exclude_on_skips_matching_branch()
    {
        $this->fileUtils->setCurrentBranch('GH-42');

        $config = $this->buildConfigWithBranchRef('phpcs', [], ['GH-*']);

        $this->executor->expects($this->never())->method('execute');

        $results = $this->runner->run('pre-commit', $config);

        $this->assertEmpty($results);
    }

    /** @test */
    public function exclude_on_allows_non_matching_branch()
    {
        $this->fileUtils->setCurrentBranch('main');

        $config = $this->buildConfigWithBranchRef('phpcs', [], ['GH-*']);

        $this->executor->expects($this->once())
            ->method('execute')
            ->willReturn(new FlowResult('phpcs', [], '0.00s'));

        $results = $this->runner->run('pre-commit', $config);

        $this->assertCount(1, $results);
    }

    /** @test */
    public function exclude_on_prevails_over_only_on()
    {
        $this->fileUtils->setCurrentBranch('release/beta-1');

        $config = $this->buildConfigWithBranchRef('phpcs', ['release/*'], ['release/beta-*']);

        $this->executor->expects($this->never())->method('execute');

        $results = $this->runner->run('pre-commit', $config);

        $this->assertEmpty($results);
    }

    /** @test */
    public function only_on_with_exclude_on_allows_non_excluded()
    {
        $this->fileUtils->setCurrentBranch('release/v2.0');

        $config = $this->buildConfigWithBranchRef('phpcs', ['release/*'], ['release/beta-*']);

        $this->executor->expects($this->once())
            ->method('execute')
            ->willReturn(new FlowResult('phpcs', [], '0.00s'));

        $results = $this->runner->run('pre-commit', $config);

        $this->assertCount(1, $results);
    }

    /** @test */
    public function exclude_on_without_only_on_excludes_from_all()
    {
        $this->fileUtils->setCurrentBranch('temp/experiment');

        $config = $this->buildConfigWithBranchRef('phpcs', [], ['temp/*']);

        $this->executor->expects($this->never())->method('execute');

        $results = $this->runner->run('pre-commit', $config);

        $this->assertEmpty($results);
    }

    /**
     * @param string[] $onlyFiles
     * @param string[] $excludeFiles
     */
    private function buildConfigWithJobRef(string $jobName, array $onlyFiles, array $excludeFiles): ConfigurationResult
    {
        $ref = new HookRef($jobName, [], $onlyFiles, $excludeFiles);

        return $this->buildConfig($jobName, $ref);
    }

    /**
     * @param string[] $onlyOn
     * @param string[] $excludeOn
     */
    private function buildConfigWithBranchRef(string $jobName, array $onlyOn, array $excludeOn): ConfigurationResult
    {
        $ref = new HookRef($jobName, $onlyOn, [], [], $excludeOn);

        return $this->buildConfig($jobName, $ref);
    }

    private function buildConfig(string $jobName, HookRef $ref): ConfigurationResult
    {
        $hookConfig = new HookConfiguration(['pre-commit' => [$ref]]);

        $jobConfig = new JobConfiguration($jobName, 'phpcs', []);

        return new ConfigurationResult(
            'githooks.php',
            new OptionsConfiguration(),
            [$jobName => $jobConfig],
            [],
            $hookConfig,
            new ValidationResult()
        );
    }
}
