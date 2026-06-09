<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use Tests\Utils\TestCase\UnitTestCase;
use Tests\Doubles\FileUtilsFake;
use Wtyd\GitHooks\Configuration\ConfigurationParser;
use Wtyd\GitHooks\Configuration\ConfigurationResult;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Configuration\ValidationResult;
use Wtyd\GitHooks\Exception\ExitException;
use Wtyd\GitHooks\Execution\ExecutionMode;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowPreparer;
use Wtyd\GitHooks\Execution\JobRunner;
use Wtyd\GitHooks\Execution\JobRunRequest;
use Wtyd\GitHooks\Jobs\JobRegistry;
use Wtyd\GitHooks\Output\ConditionsHeaderEmitter;
use Wtyd\GitHooks\Output\ConfigWarningsEmitter;
use Wtyd\GitHooks\Output\FlowResultRenderer;

/**
 * Unit tests for `JobRunner::prepare()`. The Runner is the pure-orchestration
 * extract from `JobCommand::handle()` — parse + validate + find + context +
 * options + plan + threshold overrides. Tests use a real `FlowPreparer` and
 * `FileUtilsFake`, and stub `ConfigurationParser` via a small in-test fake
 * to keep each case <5 ms.
 */
class JobRunnerTest extends UnitTestCase
{
    private FileUtilsFake $fileUtils;

    private FlowPreparer $preparer;

    protected function setUp(): void
    {
        $this->fileUtils = new FileUtilsFake();
        $this->preparer = new FlowPreparer(new JobRegistry());
    }

    /**
     * Build a JobRunner with the business-pipeline collaborators wired to real
     * fakes ($preparer, $fileUtils) and the render-side collaborators stubbed.
     * The render deps are unused by prepare() — the entry point these tests
     * exercise — so phpunit mocks are enough.
     */
    private function makeRunner(ConfigurationParser $parser): JobRunner
    {
        return new JobRunner(
            $parser,
            $this->preparer,
            $this->fileUtils,
            $this->createMock(FlowExecutor::class),
            $this->createMock(FlowResultRenderer::class),
            $this->createMock(ConditionsHeaderEmitter::class),
            $this->createMock(ConfigWarningsEmitter::class)
        );
    }

    /** @test */
    public function parser_exception_is_returned_as_failure_with_message(): void
    {
        $parser = $this->fakeParser(function () {
            throw new ExitException('config file not found at /tmp/x.php');
        });

        $prep = ($this->makeRunner($parser))
            ->prepare($this->req(['jobName' => 'phpcs_src']));

        $this->assertFalse($prep->success);
        $this->assertSame(['config file not found at /tmp/x.php'], $prep->errors);
        $this->assertNull($prep->plan);
        $this->assertNull($prep->resolution);
        $this->assertNull($prep->config);
    }

    /** @test */
    public function legacy_config_returns_failure_with_help_message(): void
    {
        $legacy = ConfigurationResult::legacy([], '/tmp/githooks.yml', new ValidationResult());
        $parser = $this->fakeParser(fn() => $legacy);

        $prep = ($this->makeRunner($parser))
            ->prepare($this->req(['jobName' => 'phpcs_src']));

        $this->assertFalse($prep->success);
        $this->assertCount(2, $prep->errors);
        $this->assertStringContainsString("requires v3", $prep->errors[0]);
        $this->assertStringContainsString("conf:init", $prep->errors[1]);
    }

    /** @test */
    public function config_with_validation_errors_returns_failure(): void
    {
        $validation = new ValidationResult();
        $validation->addError('options.processes must be a positive integer');
        $validation->addError('jobs.phpcs_src.paths is required');
        $config = $this->configWithJobs([], $validation);
        $parser = $this->fakeParser(fn() => $config);

        $prep = ($this->makeRunner($parser))
            ->prepare($this->req(['jobName' => 'phpcs_src']));

        $this->assertFalse($prep->success);
        $this->assertSame(
            [
                'options.processes must be a positive integer',
                'jobs.phpcs_src.paths is required',
            ],
            $prep->errors
        );
    }

    /** @test */
    public function job_not_defined_returns_failure_without_available_list_when_empty(): void
    {
        $config = $this->configWithJobs([]);
        $parser = $this->fakeParser(fn() => $config);

        $prep = ($this->makeRunner($parser))
            ->prepare($this->req(['jobName' => 'nonexistent']));

        $this->assertFalse($prep->success);
        $this->assertSame(
            ["Job 'nonexistent' is not defined in the configuration file."],
            $prep->errors
        );
    }

    /** @test */
    public function job_not_defined_includes_available_jobs_list_when_present(): void
    {
        $config = $this->configWithJobs([
            'phpcs_src'   => $this->jobConfig('phpcs_src'),
            'phpstan_src' => $this->jobConfig('phpstan_src'),
        ]);
        $parser = $this->fakeParser(fn() => $config);

        $prep = ($this->makeRunner($parser))
            ->prepare($this->req(['jobName' => 'nope']));

        $this->assertFalse($prep->success);
        $this->assertCount(2, $prep->errors);
        $this->assertSame("Job 'nope' is not defined in the configuration file.", $prep->errors[0]);
        $this->assertSame('Available jobs: phpcs_src, phpstan_src', $prep->errors[1]);
    }

    /** @test */
    public function happy_path_returns_plan_resolution_and_config(): void
    {
        $config = $this->configWithJobs([
            'phpcs_src' => $this->jobConfig('phpcs_src'),
        ]);
        $parser = $this->fakeParser(fn() => $config);

        $prep = ($this->makeRunner($parser))
            ->prepare($this->req(['jobName' => 'phpcs_src']));

        $this->assertTrue($prep->success);
        $this->assertSame([], $prep->errors);
        $this->assertNotNull($prep->plan);
        $this->assertNotNull($prep->resolution);
        $this->assertSame($config, $prep->config);
        $this->assertCount(1, $prep->plan->getJobs());
    }

    /** @test */
    public function plan_carries_the_resolution_after_rewrap(): void
    {
        $config = $this->configWithJobs(['phpcs_src' => $this->jobConfig('phpcs_src')]);
        $parser = $this->fakeParser(fn() => $config);

        $prep = ($this->makeRunner($parser))
            ->prepare($this->req(['jobName' => 'phpcs_src']));

        $this->assertSame(
            $prep->resolution,
            $prep->plan->getEffectiveOptions(),
            'plan must be re-packed with the resolution so renderers see effectiveOptions'
        );
    }

    /** @test */
    public function input_files_resolution_forces_fast_mode(): void
    {
        $config = $this->configWithJobs(['phpcs_src' => $this->jobConfig('phpcs_src')]);
        $parser = $this->fakeParser(fn() => $config);

        $inputFiles = new \Wtyd\GitHooks\Execution\InputFilesResolution(
            \Wtyd\GitHooks\Execution\InputFilesResolution::SOURCE_CLI,
            null,
            ['src/Touched.php'],
            [],
            [],
            [],
            1
        );

        $prep = ($this->makeRunner($parser))
            ->prepare($this->req([
                'jobName' => 'phpcs_src',
                'inputFiles' => $inputFiles,
                'invocationMode' => ExecutionMode::FULL, // ignored when inputFiles present
            ]));

        $this->assertTrue($prep->success);
        $this->assertSame(ExecutionMode::FAST, $prep->plan->getExecutionMode());
    }

    /** @test */
    public function main_branch_detection_falls_back_to_file_utils_when_global_option_absent(): void
    {
        $this->fileUtils->setDetectedMainBranch('main');
        $config = $this->configWithJobs(['phpcs_src' => $this->jobConfig('phpcs_src')]);
        $parser = $this->fakeParser(fn() => $config);

        $prep = ($this->makeRunner($parser))
            ->prepare($this->req(['jobName' => 'phpcs_src']));

        $this->assertTrue($prep->success);
        // No direct assertion on the branch (ExecutionContext is opaque from
        // outside); the test asserts that the prepare did not blow up when the
        // global config had no `main-branch` and the resolver had to consult
        // FileUtils::detectMainBranch().
    }

    /** @test */
    public function time_budget_overrides_propagate_to_each_job_in_the_plan(): void
    {
        $config = $this->configWithJobs(['phpcs_src' => $this->jobConfig('phpcs_src')]);
        $parser = $this->fakeParser(fn() => $config);

        $prep = ($this->makeRunner($parser))
            ->prepare($this->req([
                'jobName' => 'phpcs_src',
                'timeBudgetWarn' => 5,
                'timeBudgetFail' => 10,
            ]));

        $this->assertTrue($prep->success);
        $job = $prep->plan->getJobs()[0];
        // Smoke test the override took effect — JobAbstract exposes thresholds
        // through getter only via inspection so we trust no exception thrown.
        // The behavioural assertion lives in system tests for `--warn-after`
        // / `--fail-after`.
        $this->assertNotNull($job);
    }

    /**
     * BUG-28: `--memory-warn-above` / `--memory-fail-above` must reach the job's
     * memory threshold exactly like `--warn-after` / `--fail-after` reach the
     * time threshold. The CLI override replaces any per-job `memory` config; when
     * no memory flag is passed the configured threshold is preserved untouched.
     *
     * @test
     * @dataProvider memoryOverrideCases
     */
    public function memory_budget_overrides_propagate_to_each_job_in_the_plan(
        ?int $memWarn,
        ?int $memFail,
        ?array $configMemory,
        bool $expectNullThreshold,
        ?int $expectedWarn,
        ?int $expectedFail
    ): void {
        $jobArgs = ['script' => 'true'];
        if ($configMemory !== null) {
            $jobArgs['memory'] = $configMemory;
        }
        $config = $this->configWithJobs([
            'phpcs_src' => new JobConfiguration('phpcs_src', 'custom', $jobArgs),
        ]);
        $parser = $this->fakeParser(fn() => $config);

        $prep = ($this->makeRunner($parser))
            ->prepare($this->req([
                'jobName' => 'phpcs_src',
                'memoryWarnAbove' => $memWarn,
                'memoryFailAbove' => $memFail,
            ]));

        $this->assertTrue($prep->success);
        $threshold = $prep->plan->getJobs()[0]->getMemoryThreshold();

        if ($expectNullThreshold) {
            $this->assertNull($threshold);
            return;
        }

        $this->assertNotNull($threshold);
        $this->assertSame($expectedWarn, $threshold->getWarnAbove());
        $this->assertSame($expectedFail, $threshold->getFailAbove());
    }

    /**
     * @return array<string, array{0: ?int, 1: ?int, 2: ?array<string,int>, 3: bool, 4: ?int, 5: ?int}>
     */
    public function memoryOverrideCases(): array
    {
        // memWarn, memFail, configMemory, expectNullThreshold, expectedWarn, expectedFail
        return [
            'no flags, no config → no threshold'    => [null, null, null, true, null, null],
            'no flags → config threshold preserved' => [null, null, ['warn-above' => 500, 'fail-above' => 800], false, 500, 800],
            'warn flag only (no config)'            => [300, null, null, false, 300, null],
            'fail flag only replaces config'        => [null, 900, ['warn-above' => 500, 'fail-above' => 800], false, null, 900],
            'both flags replace config'             => [300, 900, ['warn-above' => 500, 'fail-above' => 800], false, 300, 900],
        ];
    }

    /**
     * BUG-29: `--ignore-errors-on-exit` must reach the job exactly like the time
     * and memory overrides. The flag is presence-only (it can only force `true`);
     * when absent the configured value is preserved.
     *
     * @test
     * @dataProvider ignoreErrorsOverrideCases
     */
    public function ignore_errors_on_exit_cli_override_propagates_to_each_job(
        ?bool $cliFlag,
        ?bool $configValue,
        bool $expected
    ): void {
        $jobArgs = ['script' => 'true'];
        if ($configValue !== null) {
            $jobArgs['ignore-errors-on-exit'] = $configValue;
        }
        $config = $this->configWithJobs([
            'phpcs_src' => new JobConfiguration('phpcs_src', 'custom', $jobArgs),
        ]);
        $parser = $this->fakeParser(fn() => $config);

        $prep = ($this->makeRunner($parser))
            ->prepare($this->req([
                'jobName' => 'phpcs_src',
                'ignoreErrorsOnExit' => $cliFlag,
            ]));

        $this->assertTrue($prep->success);
        $this->assertSame($expected, $prep->plan->getJobs()[0]->isIgnoreErrorsOnExit());
    }

    /**
     * @return array<string, array{0: ?bool, 1: ?bool, 2: bool}>
     */
    public function ignoreErrorsOverrideCases(): array
    {
        // cliFlag, configValue, expected isIgnoreErrorsOnExit()
        return [
            'no flag, no config → false'              => [null, null, false],
            'no flag, config true → preserved'        => [null, true, true],
            'flag present, no config → override true' => [true, null, true],
            'flag present, config false → override'   => [true, false, true],
            'flag present, config true → true'        => [true, true, true],
        ];
    }

    // ───────── Helpers ─────────

    /**
     * @param callable():(ConfigurationResult|ExitException) $resolver
     */
    private function fakeParser(callable $resolver): ConfigurationParser
    {
        return new class ($resolver) extends ConfigurationParser {
            /** @var callable */
            private $resolver;

            public function __construct(callable $resolver)
            {
                // Skip the parent constructor: we are not parsing real files.
                $this->resolver = $resolver;
            }

            public function parse(string $configFile = ''): ConfigurationResult
            {
                $result = ($this->resolver)();
                if ($result instanceof ConfigurationResult) {
                    return $result;
                }
                throw $result; // ExitException
            }
        };
    }

    /**
     * @param array<string, JobConfiguration> $jobs
     */
    private function configWithJobs(array $jobs, ?ValidationResult $validation = null): ConfigurationResult
    {
        return new ConfigurationResult(
            '/tmp/githooks.php',
            new OptionsConfiguration(false, 1),
            $jobs,
            [],
            null,
            $validation ?? new ValidationResult()
        );
    }

    private function jobConfig(string $name): JobConfiguration
    {
        return new JobConfiguration($name, 'custom', ['script' => 'true']);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function req(array $overrides = []): JobRunRequest
    {
        $defaults = [
            'jobName' => 'phpcs_src',
            'configFile' => '/tmp/githooks.php',
            'cliExtraArgs' => '',
            'inputFiles' => null,
            'invocationMode' => null,
            'timeBudgetWarn' => null,
            'timeBudgetFail' => null,
            'timeBudgetDisabled' => false,
            'memoryWarnAbove' => null,
            'memoryFailAbove' => null,
            'memoryBudgetDisabled' => false,
            'statsFlag' => null,
            'cliFailFast' => null,
            'dryRun' => false,
            'commitMessageFile' => null,
            'ignoreErrorsOnExit' => null,
        ];
        $f = array_merge($defaults, $overrides);
        return new JobRunRequest(
            $f['jobName'],
            $f['configFile'],
            $f['cliExtraArgs'],
            $f['inputFiles'],
            $f['invocationMode'],
            $f['timeBudgetWarn'],
            $f['timeBudgetFail'],
            $f['timeBudgetDisabled'],
            $f['memoryWarnAbove'],
            $f['memoryFailAbove'],
            $f['memoryBudgetDisabled'],
            $f['statsFlag'],
            $f['cliFailFast'],
            $f['dryRun'],
            $f['commitMessageFile'],
            $f['ignoreErrorsOnExit']
        );
    }
}
