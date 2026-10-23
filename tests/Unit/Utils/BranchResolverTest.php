<?php

declare(strict_types=1);

namespace Tests\Unit\Utils;

use Tests\Utils\TestCase\UnitTestCase;
use Tests\Doubles\FileUtilsFake;
use Wtyd\Alfil\Utils\BranchResolution;
use Wtyd\Alfil\Utils\BranchResolver;
use Wtyd\Alfil\Utils\Exception\DetachedHeadException;

/**
 * FEAT-2 · Group C — branch detection cascade.
 *
 * Cascade order (first match wins):
 *   1. $cliBranch (--branch=X)
 *   2. $ALFIL_BRANCH env var (then the pre-4.0 $GITHOOKS_BRANCH)
 *   3. CI vars in fixed order: CI_COMMIT_REF_NAME, GITHUB_REF_NAME,
 *      BUILDKITE_BRANCH, BITBUCKET_BRANCH, CIRCLE_BRANCH,
 *      DRONE_COMMIT_BRANCH, TRAVIS_PULL_REQUEST_BRANCH, TRAVIS_BRANCH
 *   4. $fileUtils->getCurrentBranch() (git rev-parse)
 *   5. Detached HEAD or empty → DetachedHeadException
 */
class BranchResolverTest extends UnitTestCase
{
    private const CI_VARS = [
        'ALFIL_BRANCH',
        'GITHOOKS_BRANCH',
        'CI_COMMIT_REF_NAME',
        'GITHUB_REF_NAME',
        'BUILDKITE_BRANCH',
        'BITBUCKET_BRANCH',
        'CIRCLE_BRANCH',
        'DRONE_COMMIT_BRANCH',
        'TRAVIS_PULL_REQUEST_BRANCH',
        'TRAVIS_BRANCH',
    ];

    private BranchResolver $resolver;
    private FileUtilsFake $fileUtils;

    protected function setUp(): void
    {
        // Clear every env var the resolver may look at so each test starts
        // from a deterministic baseline. CI / dev shells often have a couple
        // of these set even off-pipeline.
        foreach (self::CI_VARS as $var) {
            putenv($var);
        }
        $this->resolver = new BranchResolver();
        $this->fileUtils = new FileUtilsFake();
    }

    protected function tearDown(): void
    {
        foreach (self::CI_VARS as $var) {
            putenv($var);
        }
    }

    /** @test */
    public function C1_cli_branch_wins_over_everything()
    {
        putenv('GITHOOKS_BRANCH=fromenv');
        putenv('CI_COMMIT_REF_NAME=fromgitlab');
        $this->fileUtils->setCurrentBranch('feature/x');

        $resolution = $this->resolver->resolve('main', $this->fileUtils);

        $this->assertSame('main', $resolution->getBranch());
        $this->assertSame('cli', $resolution->getSource());
    }

    /** @test */
    public function C2_githooks_branch_env_wins_over_ci_vars_and_git()
    {
        putenv('GITHOOKS_BRANCH=develop');
        putenv('CI_COMMIT_REF_NAME=fromgitlab');
        $this->fileUtils->setCurrentBranch('feature/x');

        $resolution = $this->resolver->resolve(null, $this->fileUtils);

        $this->assertSame('develop', $resolution->getBranch());
        $this->assertSame('env', $resolution->getSource());
    }

    /**
     * @test
     * @dataProvider envVarRenameProvider
     *
     * Factor table B (4.0 rename): ALFIL_BRANCH and the pre-4.0 GITHOOKS_BRANCH,
     * each unset / empty / set. ALFIL_BRANCH wins whenever it has a value; the
     * old name is only read when the new one gives nothing, and only then is
     * the resolution flagged so the caller can report the deprecation. Empty
     * counts as unset for both (CI templates export empty variables).
     */
    public function C2b_alfil_branch_and_its_pre_4_0_name(
        ?string $alfil,
        ?string $githooks,
        string $expectedBranch,
        string $expectedSource,
        bool $expectLegacy
    ) {
        if ($alfil !== null) {
            putenv("ALFIL_BRANCH=$alfil");
        }
        if ($githooks !== null) {
            putenv("GITHOOKS_BRANCH=$githooks");
        }
        $this->fileUtils->setCurrentBranch('from-git');

        $resolution = $this->resolver->resolve(null, $this->fileUtils);

        $this->assertSame($expectedBranch, $resolution->getBranch());
        $this->assertSame($expectedSource, $resolution->getSource());
        $this->assertSame($expectLegacy, $resolution->isFromLegacyEnvVar());
    }

    public function envVarRenameProvider(): array
    {
        return [
            'only ALFIL_BRANCH'                     => ['new', null, 'new', 'env', false],
            'ALFIL_BRANCH wins over GITHOOKS_BRANCH' => ['new', 'old', 'new', 'env', false],
            'only GITHOOKS_BRANCH: legacy'          => [null, 'old', 'old', 'env', true],
            'empty ALFIL_BRANCH falls to the old'   => ['', 'old', 'old', 'env', true],
            'ALFIL_BRANCH with an empty old one'    => ['new', '', 'new', 'env', false],
            'both empty: git'                       => ['', '', 'from-git', 'git', false],
            'neither set: git'                      => [null, null, 'from-git', 'git', false],
        ];
    }

    /** @test */
    public function C3_gitlab_ci_var_used_when_no_cli_no_env()
    {
        putenv('CI_COMMIT_REF_NAME=feat/y');
        $this->fileUtils->setCurrentBranch('HEAD');

        $resolution = $this->resolver->resolve(null, $this->fileUtils);

        $this->assertSame('feat/y', $resolution->getBranch());
        $this->assertSame('ci:gitlab', $resolution->getSource());
    }

    /** @test */
    public function C4_github_actions_ref_name_when_no_higher_priority()
    {
        putenv('GITHUB_REF_NAME=pr-42');

        $resolution = $this->resolver->resolve(null, $this->fileUtils);

        $this->assertSame('pr-42', $resolution->getBranch());
        $this->assertSame('ci:github', $resolution->getSource());
    }

    /** @test */
    public function C4b_buildkite_branch_when_no_higher_priority()
    {
        putenv('BUILDKITE_BRANCH=topic/x');

        $resolution = $this->resolver->resolve(null, $this->fileUtils);

        $this->assertSame('topic/x', $resolution->getBranch());
        $this->assertSame('ci:buildkite', $resolution->getSource());
    }

    /** @test */
    public function C4c_bitbucket_branch_when_no_higher_priority()
    {
        putenv('BITBUCKET_BRANCH=topic/y');

        $resolution = $this->resolver->resolve(null, $this->fileUtils);

        $this->assertSame('topic/y', $resolution->getBranch());
        $this->assertSame('ci:bitbucket', $resolution->getSource());
    }

    /** @test */
    public function C4d_circle_branch_when_no_higher_priority()
    {
        putenv('CIRCLE_BRANCH=topic/z');

        $resolution = $this->resolver->resolve(null, $this->fileUtils);

        $this->assertSame('topic/z', $resolution->getBranch());
        $this->assertSame('ci:circle', $resolution->getSource());
    }

    /** @test */
    public function C4e_drone_branch_when_no_higher_priority()
    {
        putenv('DRONE_COMMIT_BRANCH=topic/d');

        $resolution = $this->resolver->resolve(null, $this->fileUtils);

        $this->assertSame('topic/d', $resolution->getBranch());
        $this->assertSame('ci:drone', $resolution->getSource());
    }

    /** @test */
    public function C4f_travis_pull_request_branch_wins_over_travis_branch_on_pr()
    {
        putenv('TRAVIS_PULL_REQUEST_BRANCH=pr-branch');
        putenv('TRAVIS_BRANCH=target');

        $resolution = $this->resolver->resolve(null, $this->fileUtils);

        $this->assertSame('pr-branch', $resolution->getBranch());
        $this->assertSame('ci:travis', $resolution->getSource());
    }

    /** @test */
    public function C4g_travis_branch_used_when_not_in_pr_context()
    {
        putenv('TRAVIS_BRANCH=master');

        $resolution = $this->resolver->resolve(null, $this->fileUtils);

        $this->assertSame('master', $resolution->getBranch());
        $this->assertSame('ci:travis', $resolution->getSource());
    }

    /** @test */
    public function C5_git_rev_parse_used_when_no_ci_no_env_no_cli()
    {
        $this->fileUtils->setCurrentBranch('feature/x');

        $resolution = $this->resolver->resolve(null, $this->fileUtils);

        $this->assertSame('feature/x', $resolution->getBranch());
        $this->assertSame('git', $resolution->getSource());
    }

    /** @test */
    public function C6_detached_head_throws_pedagogical_exception()
    {
        $this->fileUtils->setCurrentBranch('HEAD');

        $this->expectException(DetachedHeadException::class);
        $this->expectExceptionMessage(
            "Could not determine current branch: detached HEAD. "
            . "Use --branch=<name> or set the ALFIL_BRANCH environment variable."
        );

        $this->resolver->resolve(null, $this->fileUtils);
    }

    /** @test */
    public function C6b_empty_branch_throws_same_exception()
    {
        $this->fileUtils->setCurrentBranch('');

        $this->expectException(DetachedHeadException::class);

        $this->resolver->resolve(null, $this->fileUtils);
    }

    /** @test */
    public function C7_gitlab_wins_when_both_gitlab_and_github_vars_present()
    {
        // Fixed precedence: GitLab comes before GitHub in the search order.
        putenv('CI_COMMIT_REF_NAME=gitlab-value');
        putenv('GITHUB_REF_NAME=github-value');

        $resolution = $this->resolver->resolve(null, $this->fileUtils);

        $this->assertSame('gitlab-value', $resolution->getBranch());
        $this->assertSame('ci:gitlab', $resolution->getSource());
    }

    /** @test */
    public function cli_empty_string_falls_through_to_lower_levels()
    {
        // Defensive: a caller passing '' (e.g. from $this->option('branch') ?: null)
        // must not freeze the resolver on an empty branch.
        $this->fileUtils->setCurrentBranch('feature/x');

        $resolution = $this->resolver->resolve('', $this->fileUtils);

        $this->assertSame('feature/x', $resolution->getBranch());
        $this->assertSame('git', $resolution->getSource());
    }

    /** @test */
    public function env_var_empty_string_falls_through_to_ci_vars()
    {
        putenv('GITHOOKS_BRANCH=');   // present but empty
        putenv('CI_COMMIT_REF_NAME=feat');

        $resolution = $this->resolver->resolve(null, $this->fileUtils);

        $this->assertSame('feat', $resolution->getBranch());
        $this->assertSame('ci:gitlab', $resolution->getSource());
    }
}
