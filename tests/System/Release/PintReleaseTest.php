<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;
use Tests\Utils\Traits\GitSandboxTrait;

/**
 * 3.8 — `pint` as a native fixer job type. Laravel Pint is installed in the
 * target project, never shipped with GitHooks, so what has to travel inside the
 * `.phar` is the job type itself: the argument map (`test` → `--test`), the
 * fixer semantics (`mayApplyFixes()` / `isFixApplied()`) that drive the auto
 * re-stage, and the registration that makes `conf:check` accept the type at all.
 *
 * Required as @group release precisely because nothing here is observable from
 * the sources: a `PintJob` that never got compiled into the binary shows up as
 * "type 'pint' is not a supported tool" on the user's machine while the whole
 * unit suite stays green.
 *
 * Pint itself is stood in for by a small executable double. That keeps the test
 * hermetic and, more to the point, keeps it honest: what is under test is the
 * contract GitHooks implements around the tool, not Pint's own formatting.
 *
 * Deliberately NOT tagged `@group git`: the sandbox lives under the system temp
 * directory and never touches the project's repository, and that second tag
 * would take the class out of the release pipeline — `phpunit.xml` excludes
 * `git`, and the CI runs `--group release`, which does not lift that exclusion.
 *
 * @group release
 */
class PintReleaseTest extends ReleaseTestCase
{
    use GitSandboxTrait;

    private string $binary;

    protected function setUp(): void
    {
        parent::setUp();

        // Absolute, because the sandbox changes the working directory.
        $this->binary = (string) realpath($this->githooks);

        $this->setUpGitSandbox();
    }

    protected function tearDown(): void
    {
        $this->tearDownGitSandbox();

        parent::tearDown();
    }

    /**
     * Stand-in for `vendor/bin/pint`, reproducing the three exit-code
     * behaviours the job type is written against (verified in the QA pass
     * against Pint 1.32):
     *   - fix mode: rewrites what it is handed and exits 0, whether or not it
     *     changed anything;
     *   - fix mode over a file it cannot parse: exits 1 and rewrites nothing —
     *     the parseable files still get fixed;
     *   - `--test`: exits 1 on style issues without touching any file.
     *
     * Two markers drive it, kept distinct on purpose: UNPARSEABLE is the file
     * Pint chokes on, ALREADY CLEAN the one with nothing to fix. The rewrite
     * stamps FIXED BY PINT, which is therefore evidence the tool actually
     * touched the file — never part of the input.
     */
    private function fakePint(): string
    {
        $script = <<<'SH'
#!/bin/sh
TEST=0
for arg in "$@"; do
    [ "$arg" = "--test" ] && TEST=1
done
STATUS=0
for arg in "$@"; do
    case "$arg" in
        --*) continue ;;
    esac
    [ -f "$arg" ] || continue
    if grep -q 'UNPARSEABLE' "$arg"; then
        STATUS=1
        continue
    fi
    if grep -q 'ALREADY CLEAN' "$arg"; then continue; fi
    if [ "$TEST" = "1" ]; then
        STATUS=1
    else
        printf '<?php\n// FIXED BY PINT\n' > "$arg"
    fi
done
exit $STATUS
SH;
        file_put_contents('fake-pint', $script);
        chmod('fake-pint', 0755);

        return './fake-pint';
    }

    /**
     * @test
     *
     * The type has to be registered in the compiled binary, and its argument
     * map has to survive compilation: `test: true` becomes `--test`, its
     * absence leaves the fix-mode command alone, and the declared paths are
     * passed through in both.
     */
    public function the_pint_type_is_registered_in_the_phar_and_maps_test_to_the_flag(): void
    {
        file_put_contents('Subject.php', "<?php\n// before\n");

        $config = <<<'PHP'
<?php
return [
    'flows' => ['qa' => ['jobs' => ['pint_fix', 'pint_check']]],
    'jobs' => [
        'pint_fix'   => ['type' => 'pint', 'paths' => ['Subject.php']],
        'pint_check' => ['type' => 'pint', 'paths' => ['Subject.php'], 'test' => true],
    ],
];
PHP;
        file_put_contents('githooks.php', $config);

        exec("$this->binary flow qa --dry-run --format=json --config=githooks.php 2>/dev/null", $output, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $decoded = json_decode(implode("\n", $output), true);
        $this->assertIsArray($decoded, 'the dry run must emit a parseable payload');

        $commands = [];
        foreach ($decoded['jobs'] as $job) {
            $commands[$job['name']] = $job['command'];
        }

        $this->assertStringNotContainsString('--test', $commands['pint_fix'], 'fix mode must not ask for --test');
        $this->assertStringContainsString('Subject.php', $commands['pint_fix']);
        $this->assertStringContainsString('--test', $commands['pint_check']);
        $this->assertStringContainsString('Subject.php', $commands['pint_check']);
    }

    /**
     * @test
     * @dataProvider fixerDecisionTable
     *
     * Decision table of the fixer contract: `test` (declared or not) against
     * the tool's exit code, read through the only thing a user can observe —
     * what ends up in the index.
     *
     * | test    | exit | mayApplyFixes | isFixApplied | re-stage |
     * |---------|------|---------------|--------------|----------|
     * | absent  | 0    | true          | true         | yes      |
     * | absent  | 1    | true          | FALSE        | no       |
     * | true    | 0    | false         | false        | no       |
     * | true    | 1    | false         | false        | no       |
     *
     * Row 2 is the pathogenic one: Pint exits 1 on a file it cannot parse, and
     * re-staging there would commit a half-formatted tree. Covering only the
     * happy row leaves that guard free to disappear.
     *
     * @param array<string, mixed> $extraJobKeys
     */
    public function the_index_follows_the_fixer_decision_table(
        string $subjectContents,
        array $extraJobKeys,
        int $expectedExitCode,
        bool $expectedFixApplied,
        bool $expectsReStage
    ): void {
        $pint = $this->fakePint();
        file_put_contents('Subject.php', $subjectContents);
        shell_exec('git add Subject.php');

        $keys = '';
        foreach ($extraJobKeys as $key => $value) {
            $keys .= sprintf("            '%s' => %s,\n", $key, var_export($value, true));
        }

        $config = <<<PHP
<?php
return [
    'flows' => ['fix' => ['jobs' => ['formatter']]],
    'jobs' => [
        'formatter' => [
            'type'            => 'pint',
            'executable-path' => '$pint',
            'paths'           => ['Subject.php'],
$keys        ],
    ],
];
PHP;
        file_put_contents('githooks.php', $config);

        exec("$this->binary flow fix --format=json --config=githooks.php 2>/dev/null", $output, $exitCode);

        $this->assertSame($expectedExitCode, $exitCode, implode("\n", $output));

        // Off the payload, not off the exit code: a binary without the `pint`
        // type also exits 1, and the re-stage assertions below would then pass
        // for the wrong reason — the job never ran.
        $decoded = json_decode(implode("\n", $output), true);
        $this->assertIsArray($decoded, 'the flow must reach execution and emit a payload');
        $this->assertSame('formatter', $decoded['jobs'][0]['name']);
        $this->assertSame($expectedFixApplied, $decoded['jobs'][0]['fixApplied']);

        $staged = (string) shell_exec('git show :Subject.php');
        if ($expectsReStage) {
            $this->assertStringContainsString('FIXED BY PINT', $staged, 'the formatted file must reach the index');
            return;
        }
        $this->assertStringNotContainsString('FIXED BY PINT', $staged, 'nothing may be re-staged in this row');
        $this->assertSame($subjectContents, (string) file_get_contents('Subject.php'), 'the working tree must be untouched');
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: int, 3: bool, 4: bool}>
     */
    public function fixerDecisionTable(): array
    {
        $dirty = "<?php\n// before\n";
        $unparseable = "<?php\n// UNPARSEABLE\n";

        return [
            //                                  subject,      extra job keys,    exit, fixApplied, re-stage
            'fix mode, tool succeeds'        => [$dirty,       [],                0,    true,       true],
            'fix mode, tool fails'           => [$unparseable, [],                1,    false,      false],
            'check mode, style is clean'     => ["<?php\n// ALREADY CLEAN\n", ['test' => true], 0, false, false],
            'check mode, style issues found' => [$dirty,       ['test' => true],  1,    false,      false],
        ];
    }
}
